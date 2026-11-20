<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class AutomationMonitor
{
    public const TASKS = [
        'backup:run' => ['label' => 'Encrypted database & attachment backup', 'frequency' => 'Daily at 2:00 AM', 'cadence' => 'daily', 'timeout' => 120],
        'billing:send-overdue-reminders' => ['label' => 'Overdue bill reminders', 'frequency' => 'Daily at 9:00 AM', 'cadence' => 'daily', 'timeout' => 60],
        'pos:send-renewal-reminders' => ['label' => 'UTAK POS reminders', 'frequency' => 'Daily at 9:00 AM', 'cadence' => 'daily', 'timeout' => 60],
        'internet:send-soa-reminders' => ['label' => 'Internet SOA reminders', 'frequency' => 'Daily at 9:00 AM', 'cadence' => 'daily', 'timeout' => 60],
        'billing:send-soa-reminders' => ['label' => 'Water, electricity & rental reminders', 'frequency' => 'Daily at 9:00 AM', 'cadence' => 'daily', 'timeout' => 60],
        'notifications:deliver-workflow' => ['label' => 'Workflow & revision email delivery', 'frequency' => 'Every minute', 'cadence' => 'minute', 'timeout' => 10],
        'billing:generate-recurring' => ['label' => 'Recurring bill generation', 'frequency' => 'Every hour', 'cadence' => 'hourly', 'timeout' => 60],
    ];
    private static array $contexts = [];

    public static function heartbeat(): void
    {
        DB::table('automation_heartbeats')->updateOrInsert(['key' => 'scheduler'], ['last_seen_at' => now(), 'updated_at' => now()]);
    }

    public static function run(string $command, callable $work): int
    {
        $started = microtime(true);
        $id = DB::table('automation_runs')->insertGetId(['command' => $command, 'status' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        self::$contexts[] = ['id' => $id, 'command' => $command, 'metrics' => []];
        $code = 1;
        try {
            $code = (int) $work();
            self::resolveIneligibleReminders($command);
            if ($code === 0) self::resolve('command:'.$command);
            else self::issue('command:'.$command, 'Task completed with errors. Review its issue details and email configuration.');
            return $code;
        } catch (Throwable $error) {
            $code = 1;
            self::issue('command:'.$command, 'Task stopped unexpectedly. Check the application logs; this page does not expose exception contents.');
            throw $error;
        } finally {
            $context = array_pop(self::$contexts);
            DB::table('automation_runs')->where('id', $id)->update(['status' => $code === 0 ? 'succeeded' : 'failed', 'exit_code' => $code,
                'finished_at' => now(), 'duration_ms' => min(4294967295, (int) round((microtime(true) - $started) * 1000)),
                'metrics' => json_encode($context['metrics']), 'summary' => $code === 0 ? 'Task completed successfully.' : 'Task completed with errors; review the issues below.', 'updated_at' => now()]);
        }
    }

    public static function metrics(array $metrics): void
    {
        $index = array_key_last(self::$contexts);
        if ($index !== null) self::$contexts[$index]['metrics'] = array_merge(self::$contexts[$index]['metrics'], $metrics);
    }

    public static function issue(string $key, string $message, array $details = []): void
    {
        $context = self::$contexts[array_key_last(self::$contexts)] ?? null;
        if (!$context) return;
        $key = hash('sha256', $key);
        DB::table('automation_issues')->insertOrIgnore(['issue_key' => $key, 'command' => $context['command'], 'run_id' => $context['id'],
            'message' => $message, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'occurrences' => 0] + $details);
        DB::table('automation_issues')->where('issue_key', $key)->update(['run_id' => $context['id'], 'message' => $message,
            'last_seen_at' => now(), 'resolved_at' => null, 'occurrences' => DB::raw('occurrences + 1'), 'updated_at' => now()] + $details);
    }

    private static function resolveIneligibleReminders(string $command): void
    {
        if (!str_contains($command, 'send-') || !str_contains($command, '-reminders')) return;
        $recipients = \App\Models\NotificationSetting::recipients('accounting_voucher')->pluck('email')->map(fn ($email) => strtolower($email))->all();
        DB::table('automation_issues')->where('command', $command)->whereNull('resolved_at')->whereNotNull('request_id')->orderBy('id')->chunkById(100, function ($issues) use ($recipients, $command) {
            foreach ($issues as $issue) {
                if (!array_key_exists($issue->module ?? '', RequestModule::LABELS)) continue;
                $record = RequestModule::query($issue->module)->find($issue->request_id);
                $date = $record?->{RequestModule::dateField($issue->module)};
                $today = now('Asia/Manila')->startOfDay();
                if ($command === 'billing:send-overdue-reminders') {
                    $event = $record ? WorkflowNotifications::overdueEvent($record) : null;
                    if (!$event || !$date || $date->toDateString() >= $today->toDateString() || $record->payment
                        || !\App\Models\NotificationSetting::recipients($event, $record)->contains(fn ($recipient) => strtolower($recipient['email']) === strtolower($issue->recipient ?? ''))) {
                        DB::table('automation_issues')->where('id', $issue->id)->update(['resolved_at' => now(), 'updated_at' => now()]);
                    }
                    continue;
                }
                if (!$record || $record->status !== 'awaiting_voucher' || !$date || $date->toDateString() < $today->toDateString()
                    || !in_array(strtolower($issue->recipient ?? ''), $recipients)) {
                    DB::table('automation_issues')->where('id', $issue->id)->update(['resolved_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public static function resolve(string $key): void
    {
        DB::table('automation_issues')->where('issue_key', hash('sha256', $key))->whereNull('resolved_at')->update(['resolved_at' => now(), 'updated_at' => now()]);
    }
}
