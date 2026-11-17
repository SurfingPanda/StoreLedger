<?php

namespace App\Services;

use App\Models\RecurringSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecurringBilling
{
    public static function scheduleFor(Model $record): ?RecurringSchedule
    {
        if ($record->recurring_schedule_id) return RecurringSchedule::find($record->recurring_schedule_id);
        return RecurringSchedule::where('module', RequestModule::key($record))->where('source_id', $record->id)->first();
    }

    public static function configure(Request $request, Model $record): array
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'active' => ['required', 'boolean'], 'interval_months' => ['required', 'integer', 'in:1,3,6,12'],
            'lead_days' => ['required', 'integer', 'min:0', 'max:90'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
        ]);
        return DB::transaction(function () use ($request, $record, $data) {
            // Serialize configuration with generation, including creating a schedule for a source.
            $record = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $module = RequestModule::key($record);
            $schedule = self::scheduleFor($record);
            if ($schedule) $schedule = RecurringSchedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if (!$schedule) {
                $schedule = new RecurringSchedule([
                    'module' => $module, 'source_id' => $record->id, 'created_by' => $request->user()->id,
                    'store_name' => $record->store_name, 'anchor_date' => $record->{RequestModule::dateField($module)}, 'occurrence' => 1,
                ]);
            } elseif ((int) $schedule->interval_months !== (int) $data['interval_months']) {
                $lastDue = RequestModule::query($module)->where('recurring_schedule_id', $schedule->id)->max('recurring_due_date');
                if ($lastDue) $schedule->anchor_date = $lastDue;
                $schedule->occurrence = 1;
            }
            $schedule->fill($data);
            $schedule->next_due_date = CarbonImmutable::parse($schedule->anchor_date)->addMonthsNoOverflow($schedule->interval_months * $schedule->occurrence)->toDateString();
            $schedule->save();
            return ['message' => $schedule->active ? 'Recurring billing schedule saved.' : 'Recurring billing schedule paused.', 'recurrence' => $schedule->fresh()];
        });
    }

    public static function generate(int $id): int
    {
        return DB::transaction(function () use ($id) {
            DuplicateBills::lock();
            $schedule = RecurringSchedule::whereKey($id)->lockForUpdate()->firstOrFail();
            if (!$schedule->active) return 0;
            $sourceRecord = RequestModule::query($schedule->module)->find($schedule->source_id);
            if (($sourceRecord?->store && !$sourceRecord->store->is_active)
                || ($sourceRecord?->supplier && !$sourceRecord->supplier->is_active)
                || ($sourceRecord?->billingAccount && !$sourceRecord->billingAccount->is_active)) return 0;
            $created = 0;
            $window = CarbonImmutable::today('Asia/Manila')->addDays($schedule->lead_days);
            $module = $schedule->module;
            for ($i = 0; $i < 12 && $schedule->next_due_date->toDateString() <= $window->toDateString(); $i++) {
                $due = $schedule->next_due_date->toDateString();
                $exists = RequestModule::query($module)->where('recurring_schedule_id', $schedule->id)->where('recurring_due_date', $due)->exists();
                if (!$exists) {
                    $record = RequestModule::query($module)->getModel()->newInstance();
                    $record->store_name = $schedule->store_name;
                    $record->amount = $schedule->amount;
                    $source = RequestModule::query($module)->find($schedule->source_id);
                    $record->provider = $source?->provider;
                    $record->store_id = $source?->store_id;
                    $record->supplier_id = $source?->supplier_id;
                    $record->billing_account_id = $source?->billing_account_id;
                    $record->account_number = $source?->billingAccount?->account_number ?? $source?->account_number;
                    if ($source?->store) $record->store_name = $source->store->name;
                    if ($source?->supplier) $record->provider = $source->supplier->name;
                    $sourceDate = $source?->{RequestModule::dateField($module)};
                    if ($source?->billing_period && $sourceDate) {
                        $months = ((int) substr($due, 0, 4) - (int) $sourceDate->format('Y')) * 12
                            + (int) substr($due, 5, 2) - (int) $sourceDate->format('m');
                        $record->billing_period = CarbonImmutable::parse($source->billing_period.'-01')->addMonths($months)->format('Y-m');
                    } else {
                        $record->billing_period = substr($due, 0, 7);
                    }
                    $record->{RequestModule::dateField($module)} = $due;
                    $record->status = $module === 'utak-pos' ? 'awaiting_voucher' : 'awaiting_soa';
                    $record->created_by = $schedule->created_by;
                    $record->recurring_schedule_id = $schedule->id;
                    $record->recurring_due_date = $due;
                    if (!in_array($module, ['utak-pos', 'internet-soa'], true)) $record->module = $module;
                    // Each SOA cycle needs its own statement; do not copy an earlier month's attachment.
                    if (!DuplicateBills::matches($record)) {
                        $record->save();
                        $created++;
                    }
                }
                $schedule->occurrence++;
                $schedule->next_due_date = CarbonImmutable::parse($schedule->anchor_date)->addMonthsNoOverflow($schedule->interval_months * $schedule->occurrence)->toDateString();
            }
            $schedule->save();
            return $created;
        });
    }
}
