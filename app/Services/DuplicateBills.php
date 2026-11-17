<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Exceptions\HttpResponseException;

class DuplicateBills
{
    public static function rules(): array
    {
        return ['provider' => ['nullable', 'string', 'max:150'],
            'billing_period' => ['nullable', 'date_format:Y-m']];
    }

    // Called inside each write transaction, before locking individual bill rows.
    // A shared database row also serializes concurrent submissions on multiple workers.
    public static function lock(): void
    {
        DB::table('bill_detection_locks')->where('id', 1)->lockForUpdate()->first();
    }

    private static function normalized(?string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value ?? '')));
    }

    public static function period(Model $record): string
    {
        return $record->billing_period ?: substr((string) $record->{RequestModule::dateField(RequestModule::key($record))}, 0, 7);
    }

    public static function matches(Model $record): array
    {
        if ($record->status === 'cancelled') return [];
        $module = RequestModule::key($record);
        $date = RequestModule::dateField($module);
        $period = self::period($record);
        $query = RequestModule::query($module)->where('status', '!=', 'cancelled')
            ->where(function ($query) use ($period, $date) {
                $query->where('billing_period', $period)->orWhere(function ($query) use ($period, $date) {
                    $query->whereNull('billing_period')->where($date, '>=', $period.'-01')
                        ->where($date, '<', \Carbon\CarbonImmutable::parse($period.'-01')->addMonth()->toDateString());
                });
            });
        if ($record->exists) $query->whereKeyNot($record->id);
        return $query->orderBy('id')->get()->filter(function ($other) use ($record) {
            $provider = self::normalized($record->provider);
            $otherProvider = self::normalized($other->provider);
            $sameStore = $record->store_id && $other->store_id
                ? $record->store_id === $other->store_id : self::normalized($record->store_name) === self::normalized($other->store_name);
            $sameSupplier = $record->supplier_id && $other->supplier_id
                ? $record->supplier_id === $other->supplier_id : (!$provider || !$otherProvider || $provider === $otherProvider);
            $sameAccount = ($record->billing_account_id && $record->billing_account_id === $other->billing_account_id)
                || !$record->account_number || !$other->account_number
                || self::normalized($record->account_number) === self::normalized($other->account_number);
            return $sameStore && $sameSupplier && $sameAccount;
        })->map(fn ($other) => [
            'id' => $other->id, 'store_name' => $other->store_name, 'provider' => $other->provider,
            'billing_period' => self::period($other), 'amount' => $other->amount, 'status' => $other->status,
            'detail_url' => route($module.'.detail', $other),
        ])->values()->all();
    }

    public static function assertClear(Model $record): void
    {
        $matches = self::matches($record);
        if (!$matches) return;
        $refs = implode(', ', array_map(fn ($match) => '#'.$match['id'].' ('.str_replace('_', ' ', $match['status']).')', $matches));
        throw new HttpResponseException(response()->json(['message' => 'Possible duplicate bill detected.', 'duplicates' => $matches, 'errors' => ['duplicate_bill' => [
            'Possible duplicate bill for this store, provider and billing month: '.$refs.'. Review the matching records; correct the billing details or cancel the duplicate before proceeding.',
        ]]], 422));
    }

    public static function cancel(Request $request, Model $record): array
    {
        abort_unless(in_array($request->user()->role, ['admin', 'accounting'], true), 403);
        return DB::transaction(function () use ($request, $record) {
            self::lock();
            $record = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($record->status, ['awaiting_soa', 'awaiting_voucher', 'vouchered'], true) && !$record->payment, 409, 'Only unpaid requests can be cancelled.');
            abort_unless(self::matches($record), 409, 'No matching duplicate remains. Refresh this request.');
            $record->status = 'cancelled';
            $record->duplicate_cancelled_at = now();
            $record->workflow_version++;
            $record->save();
            $comment = $record->comments()->make(['body' => 'Request cancelled after duplicate-bill review. Original voucher details retained.']);
            $comment->user_id = $request->user()->id;
            $comment->save();
            return ['message' => 'Duplicate request cancelled. Its history and voucher details have been retained.'];
        });
    }

    public static function metadata(Request $request, Model $record): array
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['workflow_version' => ['nullable', 'integer', 'min:1'], 'provider' => ['required', 'string', 'max:150'], 'billing_period' => ['required', 'date_format:Y-m']]);
        return DB::transaction(function () use ($request, $record, $data) {
            self::lock();
            $record = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($record->status, ['awaiting_soa', 'awaiting_voucher'], true), 409, 'Use the return-for-revision workflow to correct approved or returned requests.');
            RequestRevisions::checkVersion($request, $record);
            $before = ($record->provider ?: 'Not recorded').' / '.self::period($record);
            if ($record->billing_account_id && DirectoryLinks::key($data['provider']) !== DirectoryLinks::key($record->provider ?? '')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['provider' => 'This bill is linked to a supplier account. Its provider cannot be relabelled; use the correct directory account on a new request.']);
            }
            $beforeSnapshot = RequestRevisions::snapshot($record);
            $record->fill(collect($data)->except('workflow_version')->all());
            if (!$record->billing_account_id) DirectoryLinks::assign($record);
            $record->workflow_version++;
            $record->save();
            RequestRevisions::event($record, $request, 'metadata_corrected', 'Pending billing metadata corrected by admin.', $beforeSnapshot);
            $comment = $record->comments()->make(['body' => 'Billing details corrected: '.$before.' ? '.$record->provider.' / '.self::period($record).'.']);
            $comment->user_id = $request->user()->id;
            $comment->save();
            return ['message' => 'Billing details updated. Duplicate checks have been refreshed.'];
        });
    }
}
