<?php

namespace App\Console\Commands;

use App\Models\NotificationSetting;
use App\Notifications\WorkflowUpdate;
use App\Services\AutomationMonitor;
use App\Services\DuplicateBills;
use App\Services\RequestModule;
use App\Services\WorkflowNotifications;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendOverdueReminders extends Command
{
    protected $signature = 'billing:send-overdue-reminders';
    protected $description = 'Send daily overdue reminders to the team responsible for each unpaid bill';

    public function handle(): int
    {
        return AutomationMonitor::run($this->getName(), function () {
            if (in_array(config('mail.default'), ['log', 'array'], true)) {
                $this->error('Configure a delivery mailer before sending overdue reminders.');
                return self::FAILURE;
            }
            $today = now('Asia/Manila')->toDateString();
            $sent = 0; $failed = 0;
            foreach (array_keys(RequestModule::LABELS) as $module) {
                RequestModule::query($module)->whereIn('status', ['awaiting_soa', 'awaiting_voucher', 'needs_revision', 'vouchered'])
                    ->where(RequestModule::dateField($module), '<', $today)->whereDoesntHave('payment')
                    ->chunkById(100, function ($records) use ($module, $today, &$sent, &$failed) {
                        foreach ($records as $record) {
                            $event = WorkflowNotifications::overdueEvent($record);
                            foreach (NotificationSetting::recipients($event, $record) as $recipient) {
                                $issueKey = 'overdue:'.$module.':'.$record->id.':'.$recipient['email'];
                                try {
                                    $delivered = DB::transaction(function () use ($record, $module, $today, $event, $recipient) {
                                        DuplicateBills::lock();
                                        $current = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                                        if (WorkflowNotifications::overdueEvent($current) !== $event || $current->payment
                                            || $current->{RequestModule::dateField($module)}->toDateString() >= $today) return false;
                                        $key = ['module' => $module, 'request_id' => $current->id, 'review_cycle' => $current->review_cycle,
                                            'event' => $event, 'email' => $recipient['email'], 'reminder_date' => $today];
                                        DB::table('overdue_mail_deliveries')->insertOrIgnore($key + ['created_at' => now(), 'updated_at' => now()]);
                                        $delivery = DB::table('overdue_mail_deliveries')->where($key)->lockForUpdate()->first();
                                        if ($delivery->sent_at) return false;
                                        $recipient['notifiable']->notify(new WorkflowUpdate($current, $event));
                                        DB::table('overdue_mail_deliveries')->where('id', $delivery->id)->update(['sent_at' => now(), 'updated_at' => now()]);
                                        return true;
                                    });
                                    AutomationMonitor::resolve($issueKey);
                                    if ($delivered) $sent++;
                                } catch (Throwable $error) {
                                    $failed++;
                                    AutomationMonitor::issue($issueKey, 'Overdue reminder failed. The next daily run will retry unpaid requests.',
                                        ['module' => $module, 'request_id' => $record->id, 'recipient' => $recipient['email']]);
                                    report($error);
                                }
                            }
                        }
                    });
            }
            AutomationMonitor::metrics(['sent' => $sent, 'failed' => $failed]);
            $this->info("Sent: $sent; failed: $failed.");
            return $failed ? self::FAILURE : self::SUCCESS;
        });
    }
}
