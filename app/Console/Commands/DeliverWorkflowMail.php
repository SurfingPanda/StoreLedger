<?php

namespace App\Console\Commands;

use App\Models\NotificationSetting;
use App\Notifications\WorkflowUpdate;
use App\Services\RequestModule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

class DeliverWorkflowMail extends Command
{
    protected $signature = 'notifications:deliver-workflow {--limit=100}';
    protected $description = 'Deliver pending Finance and RSO workflow emails, retrying failures';

    public function handle(): int
    {
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->error('Configure a real delivery mailer first. Pending emails were preserved.');
            return self::FAILURE;
        }
        $sent = 0; $failed = 0;
        $ids = DB::table('workflow_mail_deliveries')->whereNull('sent_at')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit(max(1, min(500, (int) $this->option('limit'))))->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$sent, &$failed) {
                $delivery = DB::table('workflow_mail_deliveries')->where('id', $id)->lockForUpdate()->first();
                if (!$delivery || $delivery->sent_at || ($delivery->next_attempt_at && $delivery->next_attempt_at > now()->toDateTimeString())) return;
                // Respect recipients switched off or removed after the event was captured.
                $allowed = NotificationSetting::recipients($delivery->event)->contains(fn ($recipient) => strtolower($recipient['email']) === $delivery->email);
                if (!$allowed) {
                    DB::table('workflow_mail_deliveries')->where('id', $id)->delete();
                    return;
                }
                try {
                    $record = RequestModule::query($delivery->module)->findOrFail($delivery->request_id);
                    if ((int) $delivery->review_cycle !== (int) $record->review_cycle) {
                        DB::table('workflow_mail_deliveries')->where('id', $id)->delete();
                        return;
                    }
                    if ($delivery->event === 'finance_payment' && $record->status !== 'vouchered') {
                        DB::table('workflow_mail_deliveries')->where('id', $id)->delete();
                        return;
                    }
                    Notification::route('mail', $delivery->email)->notify(new WorkflowUpdate($record, $delivery->event));
                    DB::table('workflow_mail_deliveries')->where('id', $id)->update(['sent_at' => now(), 'attempts' => $delivery->attempts + 1, 'last_error' => null, 'updated_at' => now()]);
                    $sent++;
                } catch (Throwable $error) {
                    DB::table('workflow_mail_deliveries')->where('id', $id)->update([
                        'attempts' => $delivery->attempts + 1, 'next_attempt_at' => now()->addMinutes(min(60, 5 * ($delivery->attempts + 1))),
                        'last_error' => 'Delivery failed; retry scheduled. Check SMTP connectivity and credentials.', 'updated_at' => now(),
                    ]);
                    $failed++;
                    $this->error('Workflow email '.$id.' failed; it remains eligible for retry.');
                }
            });
        }
        $this->info("Sent: $sent; failed: $failed.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
