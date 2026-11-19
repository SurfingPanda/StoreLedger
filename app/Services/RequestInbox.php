<?php

namespace App\Services;

use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RequestInbox
{
    // Refresh actionable notifications from current records, including midnight overdue
    // transitions. This deliberately does not depend on SMTP, email preferences, or cron.
    public static function sync(User $user): void
    {
        $keys = [];
        DB::transaction(function () use ($user, &$keys) {
            foreach (array_keys(RequestModule::LABELS) as $module) {
                if ($user->role === 'it' || ($user->role === 'rso' && !in_array($module, ['water', 'electricity', 'rental'], true))) continue;
                $query = RequestModule::query($module)->whereDoesntHave('payment');
                if ($user->role === 'finance') $query->where('status', 'vouchered');
                elseif ($user->role === 'accounting') $query->where(fn ($q) => $q->where('status', 'awaiting_voucher')
                    ->orWhere(fn ($q) => $q->where('status', 'needs_revision')->where('revision_target', 'accounting')));
                else $query->where(fn ($q) => $q->where('status', 'awaiting_soa')
                    ->orWhere(fn ($q) => $q->where('status', 'needs_revision')->where(fn ($q) => $q->whereNull('revision_target')->orWhere('revision_target', '!=', 'accounting'))));
                $query->chunkById(100, function ($records) use ($user, $module, &$keys) {
                    foreach ($records as $record) {
                        $overdue = $record->{RequestModule::dateField($module)}->toDateString() < now('Asia/Manila')->toDateString();
                        $action = match ($record->status) {
                            'awaiting_soa' => 'Attach the current SOA and confirm the amount.',
                            'awaiting_voucher' => 'Review the bill and process its voucher.',
                            'vouchered' => 'Record the completed payment and attach proof.',
                            default => $record->revision_target === 'accounting' ? 'Correct the returned voucher and resubmit it.' : 'Correct the returned bill and resubmit it.',
                        };
                        $title = $overdue ? 'Overdue SOA / renewal' : match ($record->status) {
                            'awaiting_soa' => 'Current SOA required', 'awaiting_voucher' => 'Voucher processing required',
                            'vouchered' => 'Payment required', default => 'Corrections required',
                        };
                        $kind = $overdue ? 'overdue' : 'action';
                        $key = self::key($user, $record, $kind);
                        $keys[] = $key;
                        self::save($user, $record, $kind, $title,
                            $record->store_name.' / '.RequestModule::LABELS[$module].' / Due '.$record->{RequestModule::dateField($module)}->format('M j, Y').'. '.$action);
                    }
                });
            }
            InboxNotification::where('user_id', $user->id)->where('role', $user->role)
                ->whereIn('kind', ['action', 'overdue'])->whereNull('resolved_at')
                ->when($keys, fn ($query) => $query->whereNotIn('notification_key', $keys))->update(['resolved_at' => now()]);
        });
    }

    public static function progress(Model $record, string $event): void
    {
        foreach (User::where('role', 'rso')->where('is_active', true)->get() as $user) {
            self::save($user, $record, 'update', $event === 'paid' ? 'Payment recorded' : 'Voucher processed',
                $record->store_name.' / '.RequestModule::LABELS[RequestModule::key($record)].'. '
                .($event === 'paid' ? 'Finance recorded the payment. View the payment details and proof.' : 'Accounting processed the voucher. The request is awaiting payment.'), $event);
        }
    }

    private static function key(User $user, Model $record, string $kind, string $event = ''): string
    {
        return hash('sha256', implode(':', [$user->role, RequestModule::key($record), $record->id, $kind, $event,
            $record->workflow_version ?? 1, $record->status, $record->revision_target]));
    }

    private static function save(User $user, Model $record, string $kind, string $title, string $body, string $event = ''): void
    {
        InboxNotification::upsert([['user_id' => $user->id, 'role' => $user->role,
            'notification_key' => self::key($user, $record, $kind, $event), 'module' => RequestModule::key($record),
            'request_id' => $record->id, 'kind' => $kind, 'title' => $title, 'body' => $body,
            'resolved_at' => null, 'created_at' => now(), 'updated_at' => now()]],
            ['user_id', 'notification_key'], ['title', 'body', 'resolved_at', 'updated_at']);
    }
}
