<?php

namespace App\Services;

use App\Models\NotificationSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WorkflowNotifications
{
    public static function voucherProcessed(Model $record): void
    {
        RequestInbox::progress($record, 'vouchered');
        self::capture($record, 'finance_payment');
        self::capture($record, 'rso_voucher_processed');
    }

    public static function paymentProcessed(Model $record): void
    {
        RequestInbox::progress($record, 'paid');
        self::capture($record, 'rso_payment_processed');
    }

    public static function returned(Model $record): void
    {
        self::capture($record, $record->revision_target === 'accounting' ? 'accounting_revision' : 'requester_revision');
    }

    public static function resubmitted(Model $record): void
    {
        self::capture($record, 'accounting_voucher');
    }

    public static function overdueEvent(Model $record): ?string
    {
        return match ($record->status) {
            'vouchered' => 'finance_overdue',
            'awaiting_voucher' => 'accounting_overdue',
            'awaiting_soa' => 'requester_overdue',
            'needs_revision' => $record->revision_target === 'accounting' ? 'accounting_overdue' : 'requester_overdue',
            default => null,
        };
    }

    private static function capture(Model $record, string $event): void
    {
        foreach (NotificationSetting::recipients($event, $record) as $recipient) {
            DB::table('workflow_mail_deliveries')->insertOrIgnore([
                'module' => RequestModule::key($record), 'request_id' => $record->id,
                'review_cycle' => $record->review_cycle ?? 1, 'event' => $event, 'email' => strtolower($recipient['email']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
