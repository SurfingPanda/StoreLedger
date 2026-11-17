<?php

namespace Tests\Feature;

use App\Models\BillingSoa;
use App\Models\InternetSoa;
use App\Models\NotificationSetting;
use App\Models\PosRenewal;
use App\Models\User;
use App\Notifications\WorkflowUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowAutomationTest extends TestCase
{
    use RefreshDatabase;

    public static function modules(): array { return [['utak-pos'], ['internet-soa'], ['water'], ['electricity'], ['rental']]; }

    private function requestRecord(string $module)
    {
        $record = match ($module) { 'utak-pos' => new PosRenewal(), 'internet-soa' => new InternetSoa(), default => new BillingSoa() };
        $record->store_name = 'BW Test'; $record->amount = '2000.50';
        $field = $module === 'utak-pos' ? 'renewal_date' : 'due_date';
        $record->$field = now()->addDays(7)->toDateString();
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        if ($module !== 'utak-pos') { $record->attachment_path = 'test.pdf'; $record->attachment_name = 'test.pdf'; }
        if ($record instanceof BillingSoa) $record->module = $module;
        $record->save(); return $record;
    }

    #[DataProvider('modules')]
    public function test_voucher_and_payment_capture_and_deliver_separate_finance_and_rso_emails(string $module): void
    {
        Storage::fake('local'); Notification::fake(); config(['mail.default' => 'smtp']);
        User::factory()->create(['role' => 'finance']); User::factory()->create(['role' => 'rso']);
        $record = $this->requestRecord($module);
        $this->actingAs(User::factory()->create(['role' => 'accounting']))
            ->postJson(route($module.'.voucher', $record), ['action' => 'process', 'voucher_reference' => 'V-001'])->assertOk();
        $this->assertDatabaseCount('workflow_mail_deliveries', 2);
        $this->artisan('notifications:deliver-workflow')->assertSuccessful();
        $this->artisan('notifications:deliver-workflow')->assertSuccessful();
        Notification::assertCount(2);
        Notification::assertSentOnDemand(WorkflowUpdate::class, fn ($mail) => $mail->event === 'finance_payment');
        Notification::assertSentOnDemand(WorkflowUpdate::class, fn ($mail) => $mail->event === 'rso_voucher_processed');
        $this->postJson(route($module.'.voucher', $record), ['action' => 'process'])->assertConflict();
        $this->actingAs(User::where('role', 'finance')->first())->post(route($module.'.payment', $record), [
            'paid_on' => now()->toDateString(), 'method' => 'cash', 'reference' => 'P-001',
            'proof' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->artisan('notifications:deliver-workflow')->assertSuccessful();
        Notification::assertCount(3);
        Notification::assertSentOnDemand(WorkflowUpdate::class, fn ($mail) => $mail->event === 'rso_payment_processed');
        $this->assertSame(3, DB::table('workflow_mail_deliveries')->whereNotNull('sent_at')->count());
    }

    public function test_smtp_failure_retries_without_undoing_voucher_and_custom_off_settings_are_respected(): void
    {
        config(['mail.default' => 'smtp']);
        NotificationSetting::create(['event' => 'finance_payment', 'mode' => 'custom', 'emails' => ['custom@example.com']]);
        NotificationSetting::create(['event' => 'rso_voucher_processed', 'mode' => 'off', 'emails' => []]);
        $record = $this->requestRecord('utak-pos');
        $this->actingAs(User::factory()->create(['role' => 'accounting']))->postJson(route('utak-pos.voucher', $record), ['action' => 'process'])->assertOk();
        $this->assertDatabaseCount('workflow_mail_deliveries', 1);
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->artisan('notifications:deliver-workflow')->assertFailed();
        $this->assertSame('vouchered', $record->fresh()->status);
        $this->assertDatabaseHas('workflow_mail_deliveries', ['email' => 'custom@example.com', 'sent_at' => null, 'attempts' => 1]);
        Notification::fake();
        $this->artisan('notifications:deliver-workflow')->assertSuccessful(); Notification::assertNothingSent();
        $this->travel(6)->minutes();
        $this->artisan('notifications:deliver-workflow')->assertSuccessful(); Notification::assertCount(1);
        $this->assertDatabaseHas('workflow_mail_deliveries', ['attempts' => 2]);
    }

    public function test_log_mailer_preserves_pending_delivery_and_paid_requests_do_not_receive_late_finance_prompts(): void
    {
        User::factory()->create(['role' => 'finance']);
        $record = $this->requestRecord('utak-pos');
        $this->actingAs(User::factory()->create(['role' => 'accounting']))->postJson(route('utak-pos.voucher', $record), ['action' => 'process'])->assertOk();
        config(['mail.default' => 'log']); Notification::fake();
        $this->artisan('notifications:deliver-workflow')->assertFailed();
        $this->assertDatabaseHas('workflow_mail_deliveries', ['sent_at' => null, 'attempts' => 0]);
        $record->status = 'paid'; $record->save(); config(['mail.default' => 'smtp']);
        $this->artisan('notifications:deliver-workflow')->assertSuccessful(); Notification::assertNothingSent();
    }
}
