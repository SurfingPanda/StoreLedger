<?php

namespace Tests\Feature;

use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\WorkflowUpdate;
use App\Services\RequestModule;
use App\Services\WorkflowNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowProcessFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); Storage::fake('local'); Notification::fake();
        config(['mail.default' => 'smtp']);
    }

    public static function modules(): array { return [['utak-pos'], ['internet-soa'], ['water'], ['electricity'], ['rental']]; }
    public static function rsoModules(): array { return [['water'], ['electricity'], ['rental']]; }

    private function record(string $module, string $status = 'awaiting_voucher', string $store = 'Review store')
    {
        $record = RequestModule::query($module)->getModel()->newInstance();
        $record->store_name = $store; $record->amount = 100; $record->status = $status;
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        $record->{RequestModule::dateField($module)} = now('Asia/Manila')->subDay()->toDateString();
        if ($module !== 'utak-pos') {
            $record->attachment_name = 'bill.pdf'; $record->attachment_path = 'bill.pdf';
            Storage::disk('local')->put('bill.pdf', 'Statement');
        }
        if (in_array($module, ['water', 'electricity', 'rental'])) $record->module = $module;
        $record->save(); return $record->fresh();
    }

    #[DataProvider('modules')]
    public function test_voucher_reference_and_cancellation_reason_are_required_and_persisted(string $module): void
    {
        $record = $this->record($module);
        $this->actingAs(User::factory()->create(['role' => 'accounting']));
        $url = route($module.'.voucher', $record);
        foreach (['', '   '] as $empty) {
            $this->postJson($url, ['action' => 'process', 'voucher_reference' => $empty])->assertUnprocessable()->assertJsonValidationErrors('voucher_reference');
            $this->postJson($url, ['action' => 'cancel', 'cancellation_reason' => $empty])->assertUnprocessable()->assertJsonValidationErrors('cancellation_reason');
        }
        $this->postJson($url, ['action' => 'process', 'voucher_reference' => 'V-REVIEW'])->assertOk()->assertJsonPath('record.voucher_reference', 'V-REVIEW');
        $cancel = $this->record($module, 'awaiting_voucher', 'Cancelled store');
        $this->postJson(route($module.'.voucher', $cancel), ['action' => 'cancel', 'cancellation_reason' => 'Supplier withdrew this bill.'])
            ->assertOk()->assertJsonPath('record.cancellation_reason', 'Supplier withdrew this bill.');
        $this->getJson(route($module.'.details', $cancel))->assertOk()->assertJsonPath('record.cancellation_reason', 'Supplier withdrew this bill.');
        $this->assertDatabaseHas('security_audit_logs', ['event' => 'request.cancelled', 'record_id' => $cancel->id]);
    }

    #[DataProvider('modules')]
    public function test_payment_accepts_philippine_today_at_midnight_and_rejects_tomorrow(string $module): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 16:30:00', 'UTC'));
        $record = $this->record($module, 'vouchered');
        $this->actingAs(User::factory()->create(['role' => 'finance']));
        $payload = ['method' => 'cash', 'reference' => 'PH-TODAY', 'proof' => UploadedFile::fake()->create('proof.pdf', 1, 'application/pdf')];
        $this->postJson(route($module.'.payment', $record), $payload + ['paid_on' => '2026-11-19'])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        $this->postJson(route($module.'.payment', $record), $payload + ['paid_on' => '2026-11-18'])->assertCreated();
        $this->assertSame('paid', $record->fresh()->status);
    }

    #[DataProvider('rsoModules')]
    public function test_any_rso_can_correct_admin_created_requests_and_accounting_is_notified(string $module): void
    {
        $record = $this->record($module);
        $accountant = User::factory()->create(['role' => 'accounting']);
        $rso = User::factory()->create(['role' => 'rso']);
        $this->actingAs($accountant)->postJson('/'.$module.'/records/'.$record->id.'/return', [
            'reason' => 'Correct the amount.', 'target' => 'requester', 'workflow_version' => $record->workflow_version,
        ])->assertOk();
        $this->assertDatabaseHas('workflow_mail_deliveries', ['event' => 'requester_revision', 'email' => $rso->email]);
        $this->artisan('notifications:deliver-workflow')->assertSuccessful();
        Notification::assertSentOnDemand(WorkflowUpdate::class, fn ($notice, $channels, $person) => $person->routes['mail'] === $rso->email && $notice->event === 'requester_revision');
        $this->actingAs($rso)->getJson(route($module.'.details', $record))->assertJsonPath('revision_permissions.can_correct', true);
        $this->postJson('/'.$module.'/records/'.$record->id.'/resubmit', [
            'workflow_version' => $record->fresh()->workflow_version, 'correction_note' => 'Shared RSO coverage correction.',
            'amount' => 150, 'due_date' => '2026-12-01', 'billing_period' => '2026-11',
        ])->assertOk();
        $this->assertSame('awaiting_voucher', $record->fresh()->status);
        $this->artisan('notifications:deliver-workflow')->assertSuccessful();
        Notification::assertSentOnDemand(WorkflowUpdate::class, fn ($notice, $channels, $person) => $notice->event === 'accounting_voucher' && $person->routes['mail'] === $accountant->email);
    }

    public function test_rso_has_no_new_correction_access_to_internet_pos_or_accounting_vouchers(): void
    {
        foreach (['utak-pos', 'internet-soa', 'water'] as $module) {
            $record = $this->record($module, 'needs_revision');
            $record->revision_target = $module === 'water' ? 'accounting' : 'requester'; $record->save();
            $this->actingAs(User::factory()->create(['role' => 'rso']));
            $this->getJson(route($module.'.details', $record))->assertOk()->assertJsonPath('revision_permissions.can_correct', false);
            $this->postJson('/'.$module.'/records/'.$record->id.'/resubmit', ['workflow_version' => $record->fresh()->workflow_version, 'correction_note' => 'Not permitted.'])->assertForbidden();
        }
    }

    #[DataProvider('modules')]
    public function test_overdue_reminders_repeat_daily_and_follow_current_stage_then_stop(string $module): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 02:00:00', 'UTC'));
        $record = $this->record($module);
        $accountant = User::factory()->create(['role' => 'accounting']);
        $finance = User::factory()->create(['role' => 'finance']);
        $this->artisan('billing:send-overdue-reminders')->assertSuccessful();
        $this->artisan('billing:send-overdue-reminders')->assertSuccessful();
        Notification::assertCount(1);
        Notification::assertSentTo($accountant, WorkflowUpdate::class, fn ($notice) => $notice->event === 'accounting_overdue');
        $this->travel(1)->days();
        $this->artisan('billing:send-overdue-reminders')->assertSuccessful(); Notification::assertCount(2);
        $record->status = 'vouchered'; $record->save();
        $this->artisan('billing:send-overdue-reminders')->assertSuccessful(); Notification::assertCount(3);
        Notification::assertSentTo($finance, WorkflowUpdate::class, fn ($notice) => $notice->event === 'finance_overdue');
        $record->status = 'paid'; $record->save();
        $this->travel(1)->days();
        $this->artisan('billing:send-overdue-reminders')->assertSuccessful(); Notification::assertCount(3);
        $record->status = 'cancelled'; $record->save();
        $this->artisan('billing:send-overdue-reminders')->assertSuccessful(); Notification::assertCount(3);
        $this->assertDatabaseCount('overdue_mail_deliveries', 3);
    }

    public function test_overdue_recipient_settings_and_requester_module_scoping_are_respected(): void
    {
        $record = $this->record('water', 'awaiting_soa');
        $rso = User::factory()->create(['role' => 'rso']);
        $this->artisan('billing:send-overdue-reminders')->assertSuccessful();
        Notification::assertSentTo($rso, WorkflowUpdate::class, fn ($notice) => $notice->event === 'requester_overdue');
        $internet = $this->record('internet-soa', 'awaiting_soa', 'Internet pending');
        $this->artisan('billing:send-overdue-reminders')->assertSuccessful();
        $this->assertDatabaseMissing('overdue_mail_deliveries', ['module' => 'internet-soa', 'email' => $rso->email]);
        NotificationSetting::create(['event' => 'requester_overdue', 'mode' => 'off', 'emails' => []]);
        $before = DB::table('overdue_mail_deliveries')->count(); $this->travel(1)->days();
        $this->artisan('billing:send-overdue-reminders')->assertSuccessful(); $this->assertDatabaseCount('overdue_mail_deliveries', $before);
    }

    public function test_corrected_requests_suppress_outdated_return_notifications_and_custom_settings_apply(): void
    {
        $record = $this->record('water', 'needs_revision'); $record->revision_target = 'requester'; $record->save();
        NotificationSetting::create(['event' => 'requester_revision', 'mode' => 'custom', 'emails' => ['corrections@example.com']]);
        WorkflowNotifications::returned($record);
        $this->assertDatabaseHas('workflow_mail_deliveries', ['event' => 'requester_revision', 'email' => 'corrections@example.com']);
        $record->status = 'awaiting_voucher'; $record->revision_target = null; $record->save();
        $this->artisan('notifications:deliver-workflow')->assertSuccessful(); Notification::assertNothingSent();
        $this->assertDatabaseCount('workflow_mail_deliveries', 0);
    }

    public function test_finance_return_notifies_accounting_with_reason_and_request_link(): void
    {
        $record = $this->record('water', 'vouchered');
        $accountant = User::factory()->create(['role' => 'accounting']);
        $rso = User::factory()->create(['role' => 'rso']);
        $this->actingAs(User::factory()->create(['role' => 'finance']))->postJson('/water/records/'.$record->id.'/return', [
            'workflow_version' => $record->workflow_version, 'reason' => 'Voucher number does not match.', 'target' => 'accounting',
        ])->assertOk();
        $this->artisan('notifications:deliver-workflow')->assertSuccessful();
        Notification::assertCount(1);
        Notification::assertSentOnDemand(WorkflowUpdate::class, function ($notice, $channels, $person) use ($record, $accountant) {
            $mail = $notice->toMail($person);
            $this->assertContains('Return reason: Voucher number does not match.', $mail->introLines);
            $this->assertSame(route('water.detail', $record), $mail->actionUrl);
            return $notice->event === 'accounting_revision' && $person->routes['mail'] === $accountant->email;
        });
        $this->assertDatabaseMissing('workflow_mail_deliveries', ['email' => $rso->email]);
    }

    public function test_overdue_reminders_exclude_today_future_cancelled_and_paid_requests(): void
    {
        User::factory()->create(['role' => 'accounting']); User::factory()->create(['role' => 'finance']);
        foreach (['today', 'future', 'cancelled', 'paid'] as $case) {
            $record = $this->record('utak-pos', in_array($case, ['paid', 'cancelled']) ? $case : 'awaiting_voucher', $case.' store');
            if (in_array($case, ['today', 'future'])) {
                $record->renewal_date = now('Asia/Manila')->addDays($case === 'today' ? 0 : 1)->toDateString(); $record->save();
            }
        }
        $this->artisan('billing:send-overdue-reminders')->assertSuccessful(); Notification::assertNothingSent();
        $this->assertDatabaseCount('overdue_mail_deliveries', 0);
        config(['mail.default' => 'log']);
        $this->artisan('billing:send-overdue-reminders')->assertFailed(); Notification::assertNothingSent();
    }

    public function test_overdue_failures_remain_visible_and_retry_without_marking_as_sent(): void
    {
        $record = $this->record('utak-pos'); User::factory()->create(['role' => 'accounting']);
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->artisan('billing:send-overdue-reminders')->assertFailed();
        $this->assertDatabaseCount('overdue_mail_deliveries', 0);
        $this->assertDatabaseHas('automation_issues', ['command' => 'billing:send-overdue-reminders', 'request_id' => $record->id, 'resolved_at' => null]);
        Notification::fake();
        $this->artisan('billing:send-overdue-reminders')->assertSuccessful(); Notification::assertCount(1);
        $this->assertDatabaseCount('overdue_mail_deliveries', 1);
        $this->assertSame(0, DB::table('automation_issues')->where('command', 'billing:send-overdue-reminders')->whereNull('resolved_at')->count());
    }
}
