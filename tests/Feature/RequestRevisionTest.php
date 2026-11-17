<?php
namespace Tests\Feature;

use App\Models\User;
use App\Models\RequestRevision;
use App\Services\RequestModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RequestRevisionTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Storage::fake('local'); }
    public static function modules(): array { return array_map(fn ($module) => [$module], array_keys(RequestModule::LABELS)); }
    private function record(string $module)
    {
        $record = RequestModule::query($module)->getModel()->newInstance();
        $record->store_name = 'BW Test'; $record->provider = 'Provider'; $record->billing_period = '2026-11';
        $record->{RequestModule::dateField($module)} = '2026-12-15'; $record->amount = '1500'; $record->status = 'awaiting_voucher';
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        if (!in_array($module, ['utak-pos', 'internet-soa'])) $record->module = $module;
        if ($module !== 'utak-pos') { $record->attachment_name = 'old.pdf'; $record->attachment_path = 'old.pdf'; Storage::disk('local')->put('old.pdf', 'old statement'); }
        $record->save(); return $record->fresh();
    }
    private function url($record, string $action): string { return '/'.RequestModule::key($record).'/records/'.$record->id.'/'.$action; }
    private function version($record): array { return ['workflow_version' => $record->fresh()->workflow_version]; }
    private function correction($record): array
    {
        return array_merge($this->version($record), ['amount' => '1750', 'billing_period' => '2026-11',
            RequestModule::dateField(RequestModule::key($record)) => '2026-12-16', 'correction_note' => 'Corrected the amount and due date.']);
    }
    private function voucher($record): void
    {
        $this->actingAs(User::factory()->create(['role' => 'accounting']))->postJson($this->url($record, 'voucher'), array_merge($this->version($record), ['action' => 'process', 'voucher_reference' => 'ORIGINAL']))->assertOk();
    }
    private function returned($record, string $role = 'accounting', string $target = 'requester'): void
    {
        $this->actingAs(User::where('role', $role)->first() ?? User::factory()->create(['role' => $role]))->postJson($this->url($record, 'return'), array_merge($this->version($record), ['reason' => 'Please correct the details.', 'target' => $target]))->assertOk();
    }

    #[DataProvider('modules')]
    public function test_accounting_return_blocks_processing_and_requester_resubmission_preserves_audit_and_statements(string $module): void
    {
        $record = $this->record($module);
        $this->returned($record);
        $this->assertSame('needs_revision', $record->fresh()->status);
        $this->postJson($this->url($record, 'voucher'), array_merge($this->version($record), ['action' => 'process']))->assertConflict();
        $this->getJson('/'.$module.'/records?status=needs_revision')->assertJsonPath('total', 1)->assertJsonPath('summary.needs_revision_count', 1);
        $this->getJson('/dashboard/records?status=needs_revision')->assertJsonPath('summary.needs_revision_count', 1)->assertJsonPath('summary.outstanding_amount', '1500.00');
        $this->actingAs(User::factory()->create(['role' => 'finance']))->postJson($this->url($record, 'payment'), [])->assertConflict();
        $this->actingAs(User::factory()->create(['role' => 'it']))->postJson($this->url($record, 'resubmit'), $this->correction($record))->assertForbidden();
        $this->actingAs(User::find($record->created_by));
        $data = $this->correction($record);
        if ($module !== 'utak-pos') $data['soa'] = UploadedFile::fake()->create('revised.pdf', 2, 'application/pdf');
        $this->post($this->url($record, 'resubmit'), $data, ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('awaiting_voucher', $record->fresh()->status);
        $this->assertSame('1750.00', $record->fresh()->amount);
        $this->assertNull($record->fresh()->voucher_reference);
        $response = $this->getJson($this->url($record, 'details'))->assertOk()->assertJsonPath('revisions.total', 2)
            ->assertJsonPath('revisions.data.0.before.amount', '1500.00')->assertJsonPath('revisions.data.0.after.amount', '1750.00');
        $this->assertStringNotContainsString('attachment_path', $response->getContent());
        if ($module !== 'utak-pos') {
            Storage::disk('local')->assertExists('old.pdf'); Storage::disk('local')->assertExists($record->fresh()->attachment_path);
            $this->get($response->json('revisions.data.0.before.attachment_url'))->assertDownload('old.pdf');
            $this->get($response->json('revisions.data.0.after.attachment_url'))->assertDownload('revised.pdf');
        }
    }

    #[DataProvider('modules')]
    public function test_finance_can_return_voucher_to_accounting_with_a_fresh_notification_cycle(string $module): void
    {
        Notification::fake(); config(['mail.default' => 'smtp']);
        User::factory()->create(['role' => 'finance']); User::factory()->create(['role' => 'rso']);
        $record = $this->record($module); $this->voucher($record);
        $this->artisan('notifications:deliver-workflow')->assertSuccessful(); Notification::assertCount(2);
        $oldVersion = $record->fresh()->workflow_version;
        $this->returned($record, 'finance', 'accounting');
        $this->actingAs(User::find($record->created_by))->postJson($this->url($record, 'resubmit'), array_merge($this->version($record), ['voucher_reference' => 'CORRECTED', 'correction_note' => 'Updated voucher.']))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'accounting']));
        $this->postJson($this->url($record, 'resubmit'), array_merge($this->version($record), ['voucher_reference' => 'CORRECTED', 'amount' => '999', 'correction_note' => 'Updated voucher.']))->assertOk();
        $this->assertSame('vouchered', $record->fresh()->status);
        $this->assertSame('CORRECTED', $record->fresh()->voucher_reference);
        $this->assertSame('1500.00', $record->fresh()->amount);
        $this->artisan('notifications:deliver-workflow')->assertSuccessful(); Notification::assertCount(4);
        $this->actingAs(User::factory()->create(['role' => 'finance']));
        $payload = ['paid_on' => now()->toDateString(), 'method' => 'cash', 'reference' => 'P-1', 'workflow_version' => $oldVersion,
            'proof' => UploadedFile::fake()->create('proof.pdf', 2, 'application/pdf')];
        $this->post($this->url($record, 'payment'), $payload, ['Accept' => 'application/json'])->assertConflict();
        unset($payload['workflow_version']);
        $this->post($this->url($record, 'payment'), $payload, ['Accept' => 'application/json'])->assertConflict();
        $payload['workflow_version'] = $record->fresh()->workflow_version;
        $this->post($this->url($record, 'payment'), $payload, ['Accept' => 'application/json'])->assertCreated();
    }

    #[DataProvider('modules')]
    public function test_finance_bill_return_invalidates_voucher_and_requires_accounting_reapproval(string $module): void
    {
        $record = $this->record($module); $this->voucher($record); $this->returned($record, 'finance', 'requester');
        $this->actingAs(User::find($record->created_by))->postJson($this->url($record, 'resubmit'), $this->correction($record))->assertOk();
        $this->assertSame('awaiting_voucher', $record->fresh()->status); $this->assertNull($record->fresh()->voucher_action_at);
        $this->actingAs(User::factory()->create(['role' => 'finance']))->postJson($this->url($record, 'payment'), [])->assertConflict();
        $this->voucher($record); $this->assertSame('vouchered', $record->fresh()->status);
        $this->assertSame('ORIGINAL', RequestRevision::where('action', 'resubmitted')->first()->before['voucher_reference']);
    }

    #[DataProvider('modules')]
    public function test_return_and_reopen_require_reason_and_correct_roles_and_paid_records_stay_locked(string $module): void
    {
        $record = $this->record($module);
        $this->actingAs(User::factory()->create(['role' => 'it']))->postJson($this->url($record, 'return'), [])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'accounting']));
        $this->postJson($this->url($record, 'return'), array_merge($this->version($record), ['reason' => ' ', 'target' => 'requester']))->assertUnprocessable();
        $this->postJson($this->url($record, 'return'), array_merge($this->version($record), ['reason' => 'Reason', 'target' => 'accounting']))->assertUnprocessable();
        $this->actingAs(User::find($record->created_by));
        $this->postJson($this->url($record, 'reopen'), array_merge($this->version($record), ['reason' => 'Reason']))->assertConflict();
        $record->status = 'cancelled'; $record->save();
        $this->postJson($this->url($record, 'reopen'), $this->version($record))->assertUnprocessable();
        $this->postJson($this->url($record, 'reopen'), array_merge($this->version($record), ['reason' => 'Cancellation was incorrect.']))->assertOk();
        $this->assertSame('needs_revision', $record->fresh()->status);
        $this->postJson($this->url($record, 'resubmit'), $this->correction($record))->assertOk();
        $record->refresh(); $record->status = 'paid'; $record->save();
        $this->postJson($this->url($record, 'reopen'), array_merge($this->version($record), ['reason' => 'Reason']))->assertConflict();
        $this->postJson($this->url($record, 'resubmit'), $this->correction($record))->assertForbidden();
    }

    #[DataProvider('modules')]
    public function test_stale_repeated_submissions_and_duplicate_corrections_do_not_overwrite_or_orphan_files(string $module): void
    {
        $record = $this->record($module); $this->returned($record);
        $this->actingAs(User::find($record->created_by));
        $stale = $this->correction($record); $stale['workflow_version'] = 1;
        $this->postJson($this->url($record, 'resubmit'), $stale)->assertConflict();
        $other = $this->record($module);
        $before = Storage::disk('local')->allFiles();
        $payload = $this->correction($record);
        if ($module !== 'utak-pos') $payload['soa'] = UploadedFile::fake()->create('replace.pdf', 2, 'application/pdf');
        $this->post($this->url($record, 'resubmit'), $payload, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('duplicate_bill');
        $this->assertSame('needs_revision', $record->fresh()->status); $this->assertSame('1500.00', $record->fresh()->amount);
        $this->assertEquals($before, Storage::disk('local')->allFiles());
        $this->assertSame(1, RequestRevision::count());
        $other->status = 'cancelled'; $other->save();
        unset($payload['soa']);
        $this->postJson($this->url($record, 'resubmit'), $payload)->assertOk();
        $this->postJson($this->url($record, 'resubmit'), $payload)->assertConflict();
    }

    #[DataProvider('modules')]
    public function test_accounting_can_escalate_finance_returns_and_metadata_cannot_bypass_approval(string $module): void
    {
        $record = $this->record($module); $this->voucher($record);
        $this->actingAs(User::find($record->created_by));
        $this->postJson($this->url($record, 'billing-metadata'), ['provider' => 'Other', 'billing_period' => '2026-10'])->assertConflict();
        $this->returned($record, 'finance', 'accounting');
        $this->returned($record, 'accounting', 'requester');
        $this->assertSame('requester', $record->fresh()->revision_target);
        $this->actingAs(User::find($record->created_by))->postJson($this->url($record, 'resubmit'), $this->correction($record))->assertOk();
        $this->assertNull($record->fresh()->voucher_reference);
        $this->assertSame(3, RequestRevision::where('request_id', $record->id)->count());
    }

    public function test_old_cycle_email_jobs_are_suppressed_and_missing_statements_cannot_be_resubmitted(): void
    {
        Notification::fake(); config(['mail.default' => 'smtp']);
        User::factory()->create(['role' => 'finance']); User::factory()->create(['role' => 'rso']);
        $record = $this->record('water'); $this->voucher($record); $this->returned($record, 'finance', 'requester');
        $this->artisan('notifications:deliver-workflow')->assertSuccessful(); Notification::assertNothingSent();
        $this->assertSame(0, DB::table('workflow_mail_deliveries')->count());
        Storage::disk('local')->delete('old.pdf');
        $this->actingAs(User::find($record->created_by))->postJson($this->url($record, 'resubmit'), $this->correction($record))->assertUnprocessable();
        $this->assertSame('needs_revision', $record->fresh()->status);
    }

    public function test_cross_module_revision_routes_are_scoped_and_archived_statements_require_authentication(): void
    {
        $record = $this->record('water'); $this->returned($record);
        $this->actingAs(User::find($record->created_by));
        $this->postJson('/rental/records/'.$record->id.'/return', ['reason' => 'Wrong module', 'target' => 'requester', 'workflow_version' => 2])->assertNotFound();
        $event = RequestRevision::firstOrFail();
        $this->get(route('request-revisions.attachment', [$event, 'before']))->assertDownload('old.pdf');
        $this->get(route('request-revisions.attachment', [$event, 'invalid']))->assertNotFound();
        auth()->logout();
        $this->get(route('request-revisions.attachment', [$event, 'before']))->assertRedirect('/login');
    }
}
