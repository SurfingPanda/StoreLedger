<?php

namespace App\Console\Commands;

use App\Models\PosRenewal;
use App\Models\NotificationSetting;
use App\Notifications\PosRenewalReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendPosRenewalReminders extends Command
{
    protected $signature = 'pos:send-renewal-reminders';
    protected $description = 'Email Accounting about unvouchered UTAK renewals due within 15 days';

    public function handle(): int
    {
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->error('Reminders were not sent: configure a delivery mailer in .env. Log/array mail is never marked as delivered.');
            return self::FAILURE;
        }
        $today = CarbonImmutable::today('Asia/Manila');
        $sent = 0;
        $failed = 0;
        PosRenewal::where('status', 'awaiting_voucher')
            ->whereBetween('renewal_date', [$today->toDateString(), $today->addDays(15)->toDateString()])
            ->chunkById(100, function ($renewals) use (&$sent, &$failed) {
                foreach ($renewals as $renewal) {
                        foreach (NotificationSetting::recipients('accounting_voucher') as $recipient) {
                            try {
                                $delivered = DB::transaction(function () use ($renewal, $recipient) {
                                    $key = ['pos_renewal_id' => $renewal->id, 'renewal_date' => $renewal->renewal_date->toDateString(), 'email' => $recipient['email']];
                                    DB::table('pos_renewal_reminders')->insertOrIgnore($key + ['user_id' => $recipient['user_id']]);
                                    $delivery = DB::table('pos_renewal_reminders')->where($key)->lockForUpdate()->first();
                                    if ($delivery->sent_at) return false;
                                    // Send synchronously: a queued job is not proof of SMTP acceptance.
                                    $recipient['notifiable']->notify(new PosRenewalReminder($renewal));
                                    DB::table('pos_renewal_reminders')->where('id', $delivery->id)->update(['sent_at' => now()]);
                                    return true;
                                });
                                if ($delivered) $sent++;
                            } catch (Throwable $error) {
                                $failed++;
                                report($error);
                                $this->error('Reminder failed for record '.$renewal->id.', recipient '.$recipient['email'].'. It remains eligible for retry.');
                            }
                        }
                }
            });
        $this->info("Sent: $sent; failed: $failed.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
