<?php
namespace Tests\Feature;

use App\Models\User;
use App\Models\PosRenewal;
use App\Models\RecurringSchedule;
use App\Services\AutomationMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AutomationHealthTest extends TestCase
{
    use RefreshDatabase;
    private function admin(): void { $this->actingAs(User::factory()->create(['role' => 'admin'])); }
    private function record(string $status = 'awaiting_voucher'): PosRenewal
    {
        $record = new PosRenewal(['store_name' => 'BW Health', 'renewal_date' => now()->addDays(5)->toDateString(), 'amount' => 1500]);
        $record->created_by = User::factory()->create(['role' => 'admin'])->id; $record->status = $status; $record->save(); return $record->fresh();
    }
    private function email(PosRenewal $record, string $recipient, array $overrides = []): int
    {
        return DB::table('workflow_mail_deliveries')->insertGetId(array_merge(['module' => 'utak-pos', 'request_id' => $record->id, 'event' => 'finance_payment', 'email' => $recipient,
            'review_cycle' => 1, 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()], $overrides));
    }

    public function test_interrupted_migration_can_resume_without_deleting_monitoring_history(): void
    {
        AutomationMonitor::run('billing:generate-recurring', fn () => 0);
        Schema::drop('automation_retry_requests');
        $migration = require database_path('migrations/2026_11_17_000007_create_automation_health_monitoring.php');
        $migration->up();
        $this->assertSame(1, DB::table('automation_runs')->count());
        $this->assertSame(1, DB::table('automation_heartbeats')->count());
        $this->assertTrue(Schema::hasTable('automation_retry_requests'));
    }

    public function test_only_admins_can_view_health_and_queue_retries(): void
    {
        $this->getJson('/automation-health/records')->assertUnauthorized();
        foreach (['it', 'accounting', 'finance', 'rso'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/automation-health')->assertForbidden(); $this->getJson('/automation-health/records')->assertForbidden();
            $this->postJson('/automation-health/emails/1/retry')->assertForbidden();
        }
        $this->admin(); $this->withoutVite()->get('/automation-health')->assertOk()->assertViewHas('appData', fn ($data) => $data['page'] === 'automation-health');
        $this->getJson('/automation-health/records')->assertOk()->assertJsonPath('scheduler.status', 'not_observed')->assertJsonPath('runs.total', 0)->assertJsonPath('summary.sent_today', 0);
    }

    public function test_manual_runs_do_not_fake_scheduler_liveness_and_stale_heartbeats_are_visible(): void
    {
        $this->admin();
        AutomationMonitor::run('billing:generate-recurring', function () { AutomationMonitor::metrics(['created' => 3]); return 0; });
        $this->getJson('/automation-health/records')->assertJsonPath('scheduler.status', 'not_observed')->assertJsonPath('runs.data.0.metrics.created', 3);
        AutomationMonitor::heartbeat();
        $this->getJson('/automation-health/records')->assertJsonPath('scheduler.status', 'healthy');
        $this->travel(4)->minutes(); $this->getJson('/automation-health/records')->assertJsonPath('scheduler.status', 'late');
        $this->travel(8)->minutes(); $this->getJson('/automation-health/records')->assertJsonPath('scheduler.status', 'missing'); $this->travelBack();
    }

    public function test_command_failures_and_exceptions_are_recorded_without_exposing_secrets(): void
    {
        $this->admin(); config(['mail.default' => 'array', 'mail.mailers.smtp.password' => 'DO-NOT-EXPOSE-SECRET']);
        $this->artisan('notifications:deliver-workflow')->assertFailed();
        try { AutomationMonitor::run('billing:generate-recurring', fn () => throw new \RuntimeException('DO-NOT-EXPOSE-SECRET')); } catch (\RuntimeException $error) {}
        $response = $this->getJson('/automation-health/records')->assertOk()->assertJsonPath('summary.open_issues', 2)->assertJsonPath('runs.data.0.status', 'failed')->assertJsonPath('mail.configured', false);
        $this->assertStringNotContainsString('DO-NOT-EXPOSE-SECRET', $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->artisan('billing:generate-recurring')->assertSuccessful();
        $this->assertSame(1, DB::table('automation_issues')->whereNull('resolved_at')->count());
    }

    public function test_workflow_failures_can_be_queued_for_retry_without_sending_or_resetting_attempts(): void
    {
        config(['mail.default' => 'smtp']);
        $finance = User::factory()->create(['role' => 'finance']); $record = $this->record('vouchered');
        $id = $this->email($record, strtolower($finance->email));
        Notification::shouldReceive('route')->andReturnSelf(); Notification::shouldReceive('notify')->andThrow(new \RuntimeException('private SMTP details'));
        $this->artisan('notifications:deliver-workflow')->assertFailed();
        $this->assertSame(1, DB::table('workflow_mail_deliveries')->find($id)->attempts);
        $this->admin();
        $this->assertStringNotContainsString('private SMTP details', $this->getJson('/automation-health/records')->getContent());
        $this->getJson('/automation-health/records?email_status=retrying')->assertOk()->assertJsonPath('emails.total', 1)->assertJsonPath('emails.data.0.can_retry', true);
        Notification::fake();
        $this->postJson('/automation-health/emails/'.$id.'/retry')->assertOk(); Notification::assertNothingSent();
        $this->assertDatabaseCount('automation_retry_requests', 1);
        $this->assertSame(1, DB::table('workflow_mail_deliveries')->find($id)->attempts);
        $this->postJson('/automation-health/emails/'.$id.'/retry')->assertConflict();
        $this->artisan('notifications:deliver-workflow')->assertSuccessful(); Notification::assertCount(1);
        $this->assertDatabaseHas('workflow_mail_deliveries', ['id' => $id, 'attempts' => 2, 'last_error' => null]);
        $this->assertSame(0, DB::table('automation_issues')->whereNull('resolved_at')->count());
        $this->postJson('/automation-health/emails/'.$id.'/retry')->assertConflict();
        $this->getJson('/automation-health/records?email_status=sent')->assertJsonPath('emails.total', 1)->assertJsonPath('summary.sent_today', 1);
    }

    public function test_accounting_reminder_errors_are_persistent_and_resolve_after_retry(): void
    {
        config(['mail.default' => 'smtp']);
        User::factory()->create(['role' => 'accounting']); $this->record();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('private transport failure'));
        $this->artisan('pos:send-renewal-reminders')->assertFailed();
        $this->assertSame(1, DB::table('automation_issues')->whereNotNull('recipient')->whereNull('resolved_at')->count());
        $this->assertSame(0, DB::table('pos_renewal_reminders')->count());
        Notification::fake(); $this->artisan('pos:send-renewal-reminders')->assertSuccessful();
        $this->assertSame(0, DB::table('automation_issues')->whereNull('resolved_at')->count());
        $run = DB::table('automation_runs')->orderByDesc('id')->first(); $this->assertSame(1, json_decode($run->metrics, true)['sent']);
    }

    public function test_generation_continues_after_one_schedule_fails_and_captures_the_schedule_id(): void
    {
        $source = $this->record();
        $source->renewal_date = now()->subMonthNoOverflow()->toDateString(); $source->save();
        $payload = ['source_id' => $source->id, 'store_name' => 'BW Health', 'created_by' => $source->created_by, 'active' => true,
            'interval_months' => 1, 'lead_days' => 15, 'amount' => 1500, 'anchor_date' => now()->subMonth()->toDateString(), 'occurrence' => 1, 'next_due_date' => now()->toDateString()];
        $bad = RecurringSchedule::create(['module' => 'invalid-module'] + $payload);
        RecurringSchedule::create(['module' => 'utak-pos'] + $payload);
        $this->artisan('billing:generate-recurring')->assertFailed();
        $this->assertSame(2, PosRenewal::count());
        $this->assertDatabaseHas('automation_issues', ['schedule_id' => $bad->id, 'resolved_at' => null]);
        $run = DB::table('automation_runs')->first(); $this->assertSame(['created' => 1, 'failed' => 1], json_decode($run->metrics, true));
    }

    public function test_status_filters_and_pagination_validate_input_and_detect_stalled_jobs(): void
    {
        $this->admin();
        for ($i = 0; $i < 18; $i++) DB::table('automation_runs')->insert(['command' => 'billing:generate-recurring', 'status' => 'succeeded', 'started_at' => now(), 'finished_at' => now(), 'exit_code' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('automation_runs')->insert(['command' => 'notifications:deliver-workflow', 'status' => 'running', 'started_at' => now()->subMinutes(20), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('automation_runs')->insert(['command' => 'pos:send-renewal-reminders', 'status' => 'running', 'started_at' => now()->subMinutes(20), 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/automation-health/records?run_status=stalled')->assertJsonPath('runs.total', 1)->assertJsonPath('runs.data.0.is_stalled', true);
        $this->getJson('/automation-health/records?command=billing:generate-recurring&runs_page=2')->assertJsonPath('runs.total', 18)->assertJsonCount(3, 'runs.data');
        $this->getJson('/automation-health/records?command=unknown')->assertUnprocessable();
        $this->getJson('/automation-health/records?runs_page=0')->assertUnprocessable();
        $this->postJson('/automation-health/emails/99999/retry')->assertNotFound();
    }
}
