<?php

namespace App\Services;

use App\Models\NotificationSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WorkflowNotifications
{
    public static function voucherProcessed(Model $record): void
    {
        self::capture($record, 'finance_payment');
        self::capture($record, 'rso_voucher_processed');
    }

    public static function paymentProcessed(Model $record): void
    {
        self::capture($record, 'rso_payment_processed');
    }

    private static function capture(Model $record, string $event): void
    {
        foreach (NotificationSetting::recipients($event) as $recipient) {
            DB::table('workflow_mail_deliveries')->insertOrIgnore([
                'module' => RequestModule::key($record), 'request_id' => $record->id,
                'review_cycle' => $record->review_cycle ?? 1, 'event' => $event, 'email' => strtolower($recipient['email']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
