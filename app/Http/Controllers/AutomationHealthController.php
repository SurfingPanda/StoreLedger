<?php
namespace App\Http\Controllers;

use App\Models\RecurringSchedule;
use App\Services\AutomationMonitor;
use App\Services\TableSort;
use App\Services\RequestModule;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AutomationHealthController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'command' => ['nullable', Rule::in(array_merge(['all'], array_keys(AutomationMonitor::TASKS)))],
            'run_status' => ['nullable', 'in:all,running,succeeded,failed,stalled'],
            'email_status' => ['nullable', 'in:all,pending,retrying,sent'],
            'issue_status' => ['nullable', 'in:open,resolved,all'],
            'runs_page' => ['nullable', 'integer', 'min:1'], 'emails_page' => ['nullable', 'integer', 'min:1'], 'issues_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $now = CarbonImmutable::now();
        $heartbeat = DB::table('automation_heartbeats')->where('key', 'scheduler')->first();
        $seen = $heartbeat?->last_seen_at ? CarbonImmutable::parse($heartbeat->last_seen_at) : null;
        $age = $seen ? $seen->diffInSeconds($now, false) : null;
        $schedulerStatus = !$seen ? 'not_observed' : ($age < -60 ? 'clock_skew' : ($age <= 180 ? 'healthy' : ($age <= 600 ? 'late' : 'missing')));
        $tasks = collect(AutomationMonitor::TASKS)->map(function ($definition, $command) use ($now) {
            $last = DB::table('automation_runs')->where('command', $command)->orderByDesc('id')->first();
            $success = DB::table('automation_runs')->where('command', $command)->where('status', 'succeeded')->orderByDesc('id')->value('finished_at');
            $local = $now->setTimezone('Asia/Manila');
            $expected = match ($definition['cadence']) {
                'minute' => $local->startOfMinute(), 'hourly' => $local->startOfHour(),
                default => $local->setTime(9, 0) > $local ? $local->subDay()->setTime(9, 0) : $local->setTime(9, 0),
            };
            $grace = $definition['cadence'] === 'minute' ? 3 : 10;
            $late = $local > $expected->addMinutes($grace) && (!$last || CarbonImmutable::parse($last->started_at) < $expected);
            $status = !$last ? 'not_observed' : ($last->status === 'running'
                ? (CarbonImmutable::parse($last->started_at)->addMinutes($definition['timeout'] + 5) < $now ? 'stalled' : 'running')
                : ($last->status === 'failed' ? 'failed' : ($late ? 'overdue' : 'healthy')));
            if ($definition['cadence'] === 'minute' && $last && CarbonImmutable::parse($last->started_at)->addMinutes(3) < $now && $status === 'healthy') $status = 'overdue';
            return $definition + ['command' => $command, 'health' => $status, 'last_run' => $last ? $this->runRow($last) : null,
                'last_success_at' => $success ? CarbonImmutable::parse($success)->toIso8601String() : null,
                'next_due_at' => (match ($definition['cadence']) { 'minute' => $local->startOfMinute()->addMinute(), 'hourly' => $local->startOfHour()->addHour(), default => $local < $local->setTime(9, 0) ? $local->setTime(9, 0) : $local->addDay()->setTime(9, 0) })->toIso8601String()];
        })->values();
        $runs = DB::table('automation_runs');
        if (($data['command'] ?? 'all') !== 'all') $runs->where('command', $data['command']);
        $runStatus = $data['run_status'] ?? 'all';
        if ($runStatus === 'stalled') $runs->where('status', 'running')->where(function ($query) use ($now) {
            foreach (AutomationMonitor::TASKS as $command => $definition) {
                $query->orWhere(fn ($query) => $query->where('command', $command)->where('started_at', '<', $now->subMinutes($definition['timeout'] + 5)));
            }
        });
        elseif ($runStatus !== 'all') $runs->where('status', $runStatus);
        $runPage = TableSort::apply($runs, $request, ['command' => 'command', 'started_at' => 'started_at', 'status' => 'status', 'duration_ms' => 'duration_ms', 'summary' => 'summary'], 'started_at', 'desc', 'runs_')->paginate($this->pageSize($request), ['*'], 'runs_page')->through(fn ($row) => $this->runRow($row));
        $emails = DB::table('workflow_mail_deliveries');
        $emailStatus = $data['email_status'] ?? 'pending';
        if ($emailStatus === 'sent') $emails->whereNotNull('sent_at');
        elseif ($emailStatus !== 'all') { $emails->whereNull('sent_at'); if ($emailStatus === 'retrying') $emails->where('attempts', '>', 0); }
        $emailPage = TableSort::apply($emails, $request, [
            'id' => 'id', 'email' => 'email', 'request' => 'request_id', 'state' => DB::raw("CASE WHEN sent_at IS NOT NULL THEN 'Accepted by mail server' WHEN attempts > 0 AND next_attempt_at > '".$now->toDateTimeString()."' THEN 'Retry scheduled' WHEN attempts > 0 THEN 'Ready to retry' ELSE 'Queued' END"),
            'attempts' => 'attempts', 'delivery_at' => DB::raw('COALESCE(sent_at, next_attempt_at)'),
        ], 'id', 'desc', 'emails_')->paginate($this->pageSize($request), ['id', 'module', 'request_id', 'event', 'email', 'attempts', 'next_attempt_at', 'sent_at', 'created_at', 'updated_at', 'review_cycle'], 'emails_page')
            ->through(fn ($row) => $this->dates((array) $row) + ['detail_url' => $this->link($row->module, $row->request_id),
                'can_retry' => !$row->sent_at && $row->attempts > 0 && $row->next_attempt_at && CarbonImmutable::parse($row->next_attempt_at) > $now,
                'state' => $row->sent_at ? 'sent' : ($row->attempts > 0 ? ($row->next_attempt_at && CarbonImmutable::parse($row->next_attempt_at) > $now ? 'retry_scheduled' : 'ready_to_retry') : 'queued')]);
        $issues = DB::table('automation_issues');
        if (($data['command'] ?? 'all') !== 'all') $issues->where('command', $data['command']);
        $issueStatus = $data['issue_status'] ?? 'open';
        if ($issueStatus === 'open') $issues->whereNull('resolved_at'); elseif ($issueStatus === 'resolved') $issues->whereNotNull('resolved_at');
        $issuePage = TableSort::apply($issues, $request, ['command' => 'command', 'recipient' => 'recipient', 'last_seen_at' => 'last_seen_at', 'occurrences' => 'occurrences', 'resolved_at' => DB::raw("CASE WHEN resolved_at IS NULL THEN 'Open' ELSE 'Resolved' END")], 'last_seen_at', 'desc', 'issues_')->paginate($this->pageSize($request), ['id', 'command', 'module', 'request_id', 'schedule_id', 'recipient', 'message', 'occurrences', 'first_seen_at', 'last_seen_at', 'resolved_at'], 'issues_page')
            ->through(fn ($row) => $this->dates((array) $row) + ['detail_url' => $this->link($row->module, $row->request_id)]);
        $today = $now->setTimezone('Asia/Manila')->startOfDay()->setTimezone(config('app.timezone'))->toDateTimeString();
        $sentToday = DB::table('workflow_mail_deliveries')->where('sent_at', '>=', $today)->count();
        foreach (['pos_renewal_reminders', 'internet_soa_reminders', 'billing_soa_reminders'] as $table) $sentToday += DB::table($table)->where('sent_at', '>=', $today)->count();
        $active = RecurringSchedule::where('active', true)->get();
        $blocked = $active->filter(function ($schedule) {
            if (!array_key_exists($schedule->module, RequestModule::LABELS)) return false;
            $source = RequestModule::query($schedule->module)->with(['store', 'supplier', 'billingAccount'])->find($schedule->source_id);
            return ($source?->store && !$source->store->is_active) || ($source?->supplier && !$source->supplier->is_active)
                || ($source?->billingAccount && !$source->billingAccount->is_active);
        });
        $blockedIds = $blocked->pluck('id')->all();
        $due = $active->filter(fn ($schedule) => !in_array($schedule->id, $blockedIds)
            && $schedule->next_due_date->toDateString() <= $now->setTimezone('Asia/Manila')->addDays($schedule->lead_days)->toDateString())->count();
        $driver = config('mail.default');
        $smtp = $driver === 'smtp';
        $mailConfigured = !in_array($driver, ['log', 'array'], true) && !empty(config('mail.from.address'))
            && (!$smtp || (!empty(config('mail.mailers.smtp.host')) && (!empty(config('mail.mailers.smtp.url')) || (!empty(config('mail.mailers.smtp.username')) && !empty(config('mail.mailers.smtp.password'))))));
        return response()->json(['updated_at' => $now->toIso8601String(), 'timezone' => 'Asia/Manila',
            'scheduler' => ['status' => $schedulerStatus, 'last_seen_at' => $seen?->toIso8601String(), 'monitoring_since' => $heartbeat?->created_at ? CarbonImmutable::parse($heartbeat->created_at)->toIso8601String() : null],
            'mail' => ['driver' => $driver, 'configured' => $mailConfigured, 'verified' => $sentToday > 0],
            'summary' => ['pending_emails' => DB::table('workflow_mail_deliveries')->whereNull('sent_at')->count(),
                'retrying_emails' => DB::table('workflow_mail_deliveries')->whereNull('sent_at')->where('attempts', '>', 0)->count(),
                'sent_today' => $sentToday, 'open_issues' => DB::table('automation_issues')->whereNull('resolved_at')->count(),
                'active_schedules' => $active->count(), 'paused_schedules' => RecurringSchedule::where('active', false)->count(), 'due_schedules' => $due, 'blocked_schedules' => $blocked->count()],
            'tasks' => $tasks, 'runs' => $runPage, 'emails' => $emailPage, 'issues' => $issuePage]);
    }

    private function dates(array $row): array
    {
        foreach (['sent_at', 'next_attempt_at', 'created_at', 'updated_at', 'first_seen_at', 'last_seen_at', 'resolved_at'] as $key) {
            if (!empty($row[$key])) $row[$key] = CarbonImmutable::parse($row[$key])->toIso8601String();
        }
        return $row;
    }
    private function link(?string $module, ?int $id): ?string
    {
        return $id && array_key_exists($module ?? '', RequestModule::LABELS) ? route($module.'.detail', $id) : null;
    }
    private function runRow(object $row): array
    {
        return ['id' => $row->id, 'command' => $row->command, 'status' => $row->status,
            'started_at' => CarbonImmutable::parse($row->started_at)->toIso8601String(),
            'finished_at' => $row->finished_at ? CarbonImmutable::parse($row->finished_at)->toIso8601String() : null,
            'is_stalled' => $row->status === 'running' && CarbonImmutable::parse($row->started_at)->addMinutes((AutomationMonitor::TASKS[$row->command]['timeout'] ?? 60) + 5) < now(),
            'duration_ms' => $row->duration_ms, 'exit_code' => $row->exit_code, 'metrics' => $row->metrics ? json_decode($row->metrics, true) : [], 'summary' => $row->summary];
    }
    public function retry(Request $request, int $delivery)
    {
        abort_unless($request->user()->role === 'admin', 403);
        return DB::transaction(function () use ($request, $delivery) {
            $row = DB::table('workflow_mail_deliveries')->where('id', $delivery)->lockForUpdate()->first(); abort_unless($row, 404);
            abort_if($row->sent_at || $row->attempts < 1 || !$row->next_attempt_at || CarbonImmutable::parse($row->next_attempt_at) <= now(), 409, 'This email is already sent or ready for the next delivery run. Refresh its status.');
            DB::table('workflow_mail_deliveries')->where('id', $delivery)->update(['next_attempt_at' => now(), 'updated_at' => now()]);
            DB::table('automation_retry_requests')->insert(['delivery_id' => $delivery, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            \App\Services\SecurityAudit::record('automation', 'email.retry_requested', metadata: ['delivery_id' => $delivery]);
            return response()->json(['message' => 'Retry queued for the next delivery run. The scheduler must be running; no email was sent by this button.']);
        });
    }
}
