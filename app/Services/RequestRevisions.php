<?php
namespace App\Services;

use App\Models\RequestRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RequestRevisions
{
    public static function snapshot(Model $record): array
    {
        $fields = ['store_id', 'store_name', 'supplier_id', 'billing_account_id', 'provider', 'account_number',
            'billing_period', 'amount', 'status', 'voucher_reference', 'voucher_action_by', 'voucher_action_at',
            'revision_reason', 'returned_by', 'returned_at', 'duplicate_cancelled_at', 'cancellation_reason', 'attachment_name', 'attachment_path', 'workflow_version', 'review_cycle', 'revision_target'];
        $fields[] = RequestModule::dateField(RequestModule::key($record));
        return $record->only($fields);
    }

    public static function event(Model $record, Request $request, string $action, string $reason, array $before): void
    {
        RequestRevision::create(['module' => RequestModule::key($record), 'request_id' => $record->id,
            'user_id' => $request->user()->id, 'action' => $action, 'reason' => $reason,
            'before' => $before, 'after' => self::snapshot($record)]);
    }

    public static function checkVersion(Request $request, Model $record): void
    {
        abort_if((int) $record->review_cycle > 1 && !$request->filled('workflow_version'), 409, 'This request has been revised. Refresh and submit its current version.');
        if ($request->filled('workflow_version')) abort_unless((int) $request->input('workflow_version') === (int) $record->workflow_version, 409,
            'This request changed since you opened it. Refresh and review the latest version before continuing.');
    }

    public static function history(Model $record, Request $request)
    {
        $data = $request->validate(['revisions_per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        return RequestRevision::where('module', RequestModule::key($record))->where('request_id', $record->id)
            ->with('actor')->orderByDesc('id')->paginate((int) ($data['revisions_per_page'] ?? 10), ['*'], 'revisions_page')->through(function ($event) {
                $result = $event->toArray();
                foreach (['before', 'after'] as $side) {
                    if (!empty($result[$side]['attachment_path'])) {
                        $result[$side]['attachment_url'] = route('request-revisions.attachment', [$event, $side]);
                    }
                    unset($result[$side]['attachment_path']);
                }
                return $result;
            });
    }

    public static function permissions(Request $request, Model $record): array
    {
        $role = $request->user()->role;
        return [
            'can_return' => ($role === 'accounting' && ($record->status === 'awaiting_voucher' || ($record->status === 'needs_revision' && $record->revision_target === 'accounting')))
                || ($role === 'finance' && $record->status === 'vouchered'),
            'can_correct' => $record->status === 'needs_revision' && ($record->revision_target === 'accounting'
                ? $role === 'accounting' : SubmissionAccess::allows($role, RequestModule::key($record))),
            'can_reopen' => $role === 'admin' && $record->status === 'cancelled' && !$record->payment,
        ];
    }

    public static function returnRequest(Request $request, Model $record): array
    {
        abort_unless(in_array($request->user()->role, ['accounting', 'finance'], true), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000'],
            'target' => ['required', 'in:requester,accounting'], 'workflow_version' => ['required', 'integer', 'min:1']]);
        return DB::transaction(function () use ($request, $record, $data) {
            DuplicateBills::lock();
            $record = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            self::checkVersion($request, $record);
            abort_unless(self::permissions($request, $record)['can_return'] && !$record->payment, 409, 'This request cannot be returned from its current stage.');
            abort_if($request->user()->role === 'accounting' && $data['target'] !== 'requester', 422, 'Accounting must return bill corrections to the requester.');
            $before = self::snapshot($record);
            $record->status = 'needs_revision'; $record->revision_target = $data['target'];
            $record->revision_reason = $data['reason']; $record->returned_by = $request->user()->id; $record->returned_at = now();
            $record->workflow_version++; $record->review_cycle++; $record->save();
            self::event($record, $request, 'returned', $data['reason'], $before);
            WorkflowNotifications::returned($record);
            return ['message' => 'Request returned for revision. Voucher and payment actions are paused.'];
        });
    }

    public static function reopen(Request $request, Model $record): array
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000'], 'workflow_version' => ['required', 'integer', 'min:1']]);
        return DB::transaction(function () use ($request, $record, $data) {
            DuplicateBills::lock();
            $record = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail(); self::checkVersion($request, $record);
            abort_unless(self::permissions($request, $record)['can_reopen'], 409, 'Only cancelled, unpaid requests can be reopened.');
            $before = self::snapshot($record);
            $record->status = 'needs_revision'; $record->revision_target = 'requester'; $record->revision_reason = $data['reason'];
            $record->returned_by = $request->user()->id; $record->returned_at = now(); $record->duplicate_cancelled_at = null;
            $record->cancellation_reason = null;
            $record->workflow_version++; $record->review_cycle++; $record->save();
            self::event($record, $request, 'reopened', $data['reason'], $before);
            WorkflowNotifications::returned($record);
            return ['message' => 'Request reopened for correction. It requires Accounting approval before payment.'];
        });
    }

    public static function resubmit(Request $request, Model $record): array
    {
        $request->validate(['correction_note' => ['required', 'string', 'max:5000'], 'workflow_version' => ['required', 'integer', 'min:1']]);
        $path = null;
        try {
            return DB::transaction(function () use ($request, $record, &$path) {
                DuplicateBills::lock();
                $record = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail(); self::checkVersion($request, $record);
                abort_unless(self::permissions($request, $record)['can_correct'], 403, 'Only the assigned revision team or requester/admin may correct a returned request.');
                abort_if($record->payment, 409, 'A paid request cannot be corrected.');
                $before = self::snapshot($record);
                if ($record->revision_target === 'accounting') {
                    $data = $request->validate(['voucher_reference' => ['required', 'string', 'max:100']]);
                    $record->voucher_reference = $data['voucher_reference']; $record->voucher_action_by = $request->user()->id;
                    $record->voucher_action_at = now(); $record->status = 'vouchered';
                } else {
                    $date = RequestModule::dateField(RequestModule::key($record));
                    $data = $request->validate(array_merge(DirectoryLinks::rules(), [
                        'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
                        $date => ['required', 'date_format:Y-m-d'], 'billing_period' => ['required', 'date_format:Y-m'],
                        'soa' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
                    ]));
                    $record->fill(collect($data)->except('soa')->all());
                    DirectoryLinks::assign($record);
                    if ($request->hasFile('soa') && RequestModule::key($record) !== 'utak-pos') {
                        $file = $request->file('soa'); $path = $file->store('revised-soas', 'local');
                        abort_unless($path, 500, 'Could not save the revised statement.');
                        $record->attachment_path = $path;
                        $record->attachment_name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
                    }
                    abort_if(RequestModule::key($record) !== 'utak-pos' && (!$record->attachment_path || !Storage::disk('local')->exists($record->attachment_path)), 422, 'Attach a current SOA before resubmitting.');
                    $record->voucher_reference = null; $record->voucher_action_at = null; $record->voucher_action_by = null;
                    $record->status = 'awaiting_voucher';
                }
                DuplicateBills::assertClear($record);
                $record->revision_target = null; $record->revision_reason = null; $record->returned_at = null; $record->returned_by = null;
                $record->workflow_version++; $record->save();
                self::event($record, $request, 'resubmitted', $request->input('correction_note'), $before);
                if ($record->status === 'vouchered') WorkflowNotifications::voucherProcessed($record);
                else WorkflowNotifications::resubmitted($record);
                return ['message' => $record->status === 'vouchered' ? 'Voucher corrected and resubmitted to Finance.' : 'Corrections saved and resubmitted for Accounting approval.'];
            });
        } catch (Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            throw $error;
        }
    }
}
