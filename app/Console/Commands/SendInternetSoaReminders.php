<?php

namespace App\Console\Commands;

use App\Models\InternetSoa;
use App\Models\NotificationSetting;
use App\Notifications\InternetSoaReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Services\AutomationMonitor;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendInternetSoaReminders extends Command
{
    protected $signature = 'internet:send-soa-reminders';
    protected $description = 'Email Accounting about unvouchered Internet SOAs due within 15 days';

    public function handle(): int
    {
        return AutomationMonitor::run($this->getName(), fn () => $this->executeAutomation());
    }

    private function executeAutomation(): int
    {
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->error('Reminders were not sent: configure a delivery mailer in .env. Log/array mail is never marked as delivered.');
            return self::FAILURE;
        }
        $today = CarbonImmutable::today('Asia/Manila');
        $sent = 0;
        $failed = 0;
        InternetSoa::where('status', 'awaiting_voucher')
            ->whereBetween('due_date', [$today->toDateString(), $today->addDays(15)->toDateString()])
            ->chunkById(100, function ($renewals) use (&$sent, &$failed) {
                foreach ($renewals as $renewal) {
                        foreach (NotificationSetting::recipients('accounting_voucher') as $recipient) {
                            try {
                                $delivered = DB::transaction(function () use ($renewal, $recipient) {
                                    $key = ['internet_soa_id' => $renewal->id, 'due_date' => $renewal->due_date->toDateString(), 'email' => $recipient['email']];
                                    DB::table('internet_soa_reminders')->insertOrIgnore($key + ['user_id' => $recipient['user_id']]);
                                    $delivery = DB::table('internet_soa_reminders')->where($key)->lockForUpdate()->first();
                                    if ($delivery->sent_at) return false;
                                    // Send synchronously: a queued job is not proof of SMTP acceptance.
                                    $recipient['notifiable']->notify(new InternetSoaReminder($renewal));
                                    DB::table('internet_soa_reminders')->where('id', $delivery->id)->update(['sent_at' => now()]);
                                    return true;
                                });
                                AutomationMonitor::resolve("reminder:".'internet-soa'.":".$renewal->id.":".$recipient['email']);
                                if ($delivered) $sent++;
                            } catch (Throwable $error) {
                                $failed++;
                                AutomationMonitor::issue("reminder:".'internet-soa'.":".$renewal->id.":".$recipient['email'], 'Accounting reminder failed. The next daily reminder run will retry eligible requests.',
                                    ['module' => 'internet-soa', 'request_id' => $renewal->id, 'recipient' => $recipient['email']]);
                                report($error);
                                $this->error('Reminder failed for record '.$renewal->id.', recipient '.$recipient['email'].'. It remains eligible for retry.');
                            }
                        }
                }
            });
        AutomationMonitor::metrics(['sent' => $sent, 'failed' => $failed]);
        $this->info("Sent: $sent; failed: $failed.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
