<?php
namespace Tests\Feature;

use App\Models\User;
use App\Models\SecurityAuditLog;
use App\Models\NotificationSetting;
use App\Services\RequestModule;
use App\Services\SecurityAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Storage::fake('local'); }
    public static function modules(): array { return array_map(fn ($module) => [$module], array_keys(RequestModule::LABELS)); }
    private function record(string $module, string $status = 'awaiting_voucher')
    {
        $record = RequestModule::query($module)->getModel()->newInstance();
        $record->store_name = 'BW Audit'; $record->amount = 1500; $record->status = $status;
        $record->{RequestModule::dateField($module)} = '2026-12-15'; $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        if (!in_array($module, ['utak-pos', 'internet-soa'])) $record->module = $module;
        if ($module !== 'utak-pos') { $record->attachment_path = 'private/bill.pdf'; $record->attachment_name = 'bill.pdf'; Storage::disk('local')->put('private/bill.pdf', 'test'); }
        $record->save(); return $record->fresh();
    }

    public function test_signins_failures_logout_and_rate_limits_are_recorded_without_credentials(): void
    {
        $user = User::factory()->create(['email' => 'audit@example.com', 'password' => 'Valid-Secret-Password-123', 'role' => 'finance']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withHeaders(['User-Agent' => 'Audit test browser', 'Authorization' => 'Bearer DO-NOT-LOG-TOKEN']);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'DO-NOT-LOG-PASSWORD'])->assertUnprocessable();
        $failed = SecurityAuditLog::where('event', 'auth.sign_in_failed')->firstOrFail();
        $this->assertNull($failed->actor_id); $this->assertSame('203.0.113.7', $failed->ip_address); $this->assertSame($user->email, $failed->metadata['attempted_email']);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'Valid-Secret-Password-123'])->assertOk();
        $login = SecurityAuditLog::where('event', 'auth.signed_in')->firstOrFail(); $this->assertSame($user->id, $login->actor_id); $this->assertSame('finance', $login->actor_role);
        $this->postJson('/logout')->assertOk(); $this->assertSame(1, SecurityAuditLog::where('event', 'auth.signed_out')->count());
        for ($i = 0; $i < 6; $i++) $this->postJson('/login', ['email' => $user->email, 'password' => 'DO-NOT-LOG-PASSWORD']);
        $this->assertSame(1, SecurityAuditLog::where('event', 'auth.sign_in_throttled')->count());
        $serialized = SecurityAuditLog::all()->toJson();
        foreach (['DO-NOT-LOG-PASSWORD', 'DO-NOT-LOG-TOKEN', 'Valid-Secret-Password-123', $user->password] as $secret) $this->assertStringNotContainsString($secret, $serialized);
    }

    public function test_account_changes_record_before_after_and_password_resets_record_only_a_flag(): void
    {
        $admin = User::factory()->create(['role' => 'admin']); $this->actingAs($admin);
        $this->postJson('/users/records', ['name' => 'Audited user', 'email' => 'audited@example.com', 'role' => 'it', 'password' => 'New-Private-Password-123', 'password_confirmation' => 'New-Private-Password-123'])->assertCreated();
        $user = User::where('email', 'audited@example.com')->firstOrFail();
        $this->postJson('/users/records/'.$user->id, ['name' => $user->name, 'email' => $user->email, 'role' => 'finance'])->assertOk();
        $entry = SecurityAuditLog::where('event', 'account.updated')->firstOrFail(); $this->assertSame('it', $entry->before['role']); $this->assertSame('finance', $entry->after['role']); $this->assertTrue($entry->metadata['sessions_revoked']);
        $this->postJson('/users/records/'.$user->id.'/password', ['password' => 'Reset-Private-Password-123', 'password_confirmation' => 'Reset-Private-Password-123'])->assertOk();
        $entry = SecurityAuditLog::where('event', 'account.password_changed')->firstOrFail(); $this->assertTrue($entry->metadata['password_changed']); $this->assertArrayNotHasKey('password', $entry->before); $this->assertArrayNotHasKey('password', $entry->after);
        $this->postJson('/users/records/'.$user->id.'/status', ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('security_audit_logs', ['event' => 'account.created', 'actor_id' => $admin->id, 'subject_id' => (string) $user->id]);
        $this->assertStringNotContainsString('Private-Password', SecurityAuditLog::all()->toJson());
        $user->name = 'Later name'; $user->save();
        $this->assertSame($admin->name, $entry->fresh()->actor_name);
    }

    public function test_recipient_changes_and_denied_admin_actions_have_audit_entries(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $settings = array_map(fn ($event) => ['event' => $event, 'mode' => 'role', 'emails' => []], array_keys(NotificationSetting::EVENTS));
        $this->postJson('/settings/notifications', ['settings' => $settings])->assertOk();
        $settings[0]['mode'] = 'custom'; $settings[0]['emails'] = ['recipient@example.com'];
        $this->postJson('/settings/notifications', ['settings' => $settings])->assertOk();
        $entry = SecurityAuditLog::where('category', 'notifications')->orderByDesc('id')->firstOrFail();
        $this->assertSame('role', $entry->before['mode']); $this->assertSame('custom', $entry->after['mode']); $this->assertSame(['recipient@example.com'], $entry->after['emails']);
        $target = User::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'finance']))->postJson('/users/records/'.$target->id.'/status', ['is_active' => false])->assertForbidden();
        $denied = SecurityAuditLog::where('event', 'access.denied')->firstOrFail(); $this->assertSame('User', $denied->subject_type); $this->assertSame((string) $target->id, $denied->subject_id); $this->assertSame(403, $denied->metadata['http_status']);
        $this->assertTrue($target->fresh()->is_active);
    }

    #[DataProvider('modules')]
    public function test_vouchers_payments_and_downloads_are_recorded_with_safe_details(string $module): void
    {
        $record = $this->record($module);
        if ($module !== 'utak-pos') {
            $this->actingAs(User::find($record->created_by))->get(route($module.'.attachment', $record))->assertDownload('bill.pdf');
            $this->assertSame(1, SecurityAuditLog::where('event', 'attachment.download_requested')->count());
        }
        $accounting = User::factory()->create(['role' => 'accounting']);
        $this->actingAs($accounting)->postJson(route($module.'.voucher', $record), ['action' => 'process', 'voucher_reference' => 'VCH-AUDIT'])->assertOk();
        $event = SecurityAuditLog::where('event', 'voucher.processed')->firstOrFail();
        $this->assertSame('awaiting_voucher', $event->before['status']); $this->assertSame('vouchered', $event->after['status']); $this->assertSame($accounting->id, $event->actor_id);
        $finance = User::factory()->create(['role' => 'finance']);
        $this->actingAs($finance)->post(route($module.'.payment', $record), ['paid_on' => now()->toDateString(), 'method' => 'cash', 'reference' => 'PAY-AUDIT',
            'proof' => UploadedFile::fake()->create('proof.pdf', 2, 'application/pdf'), 'notes' => 'DO-NOT-LOG-NOTES', 'password' => 'DO-NOT-LOG-EXTRA'], ['Accept' => 'application/json'])->assertCreated();
        $event = SecurityAuditLog::where('event', 'payment.recorded')->firstOrFail(); $this->assertTrue($event->metadata['proof_uploaded']); $this->assertSame($finance->id, $event->actor_id); $this->assertSame('PAY-AUDIT', $event->after['reference']);
        $this->get(route($module.'.payment-proof', $record))->assertDownload('proof.pdf');
        $serialized = SecurityAuditLog::all()->toJson();
        foreach (['private/bill.pdf', 'proof_path', 'attachment_path', 'DO-NOT-LOG-NOTES', 'DO-NOT-LOG-EXTRA'] as $secret) $this->assertStringNotContainsString($secret, $serialized);
    }

    public function test_rolled_back_payments_do_not_leave_success_audit_entries(): void
    {
        $record = $this->record('utak-pos', 'vouchered'); $this->actingAs(User::factory()->create(['role' => 'finance']));
        Event::listen('eloquent.created: App\\Models\\PosRenewalPayment', fn () => throw new \RuntimeException('Simulated failure after audit insertion'));
        $this->post(route('utak-pos.payment', $record), ['paid_on' => now()->toDateString(), 'method' => 'cash', 'reference' => 'FAILED', 'proof' => UploadedFile::fake()->create('proof.pdf', 2, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertDatabaseCount('pos_renewal_payments', 0); $this->assertSame(0, SecurityAuditLog::where('event', 'payment.recorded')->count()); $this->assertSame('vouchered', $record->fresh()->status);
    }

    public function test_audit_page_is_admin_only_read_only_and_filterable_with_manila_dates(): void
    {
        $this->getJson('/security-audit/records')->assertUnauthorized();
        foreach (['it', 'accounting', 'finance', 'rso'] as $role) { $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/security-audit/records')->assertForbidden(); }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        for ($i = 0; $i < 21; $i++) SecurityAuditLog::create(['request_id' => (string) \Illuminate\Support\Str::uuid(), 'category' => 'authentication', 'event' => 'auth.sign_in_failed', 'outcome' => 'failure', 'method' => 'POST', 'ip_address' => '203.0.113.25', 'metadata' => ['attempted_email' => 'filter@example.com'], 'occurred_at' => '2026-11-16 16:30:00']);
        $this->getJson('/security-audit/records?category=authentication&outcome=failure&search=filter@example.com&date_from=2026-11-17&date_to=2026-11-17&page=2')->assertOk()->assertJsonPath('records.total', 21)->assertJsonCount(1, 'records.data')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/security-audit/records?category=authentication&date_to=2026-11-16')->assertJsonPath('records.total', 0);
        $this->getJson('/security-audit/records?date_from=2026-11-18&date_to=2026-11-17')->assertUnprocessable();
        $this->getJson('/security-audit/records?category=invalid')->assertUnprocessable();
        $this->withoutVite()->get('/security-audit')->assertOk(); $this->assertSame(1, SecurityAuditLog::where('event', 'audit.viewed')->count());
        $this->getJson('/security-audit/records')->assertOk(); $this->assertSame(1, SecurityAuditLog::where('event', 'audit.viewed')->count());
        $this->deleteJson('/security-audit/records/1')->assertStatus(404);
    }

    public function test_audit_models_reject_edits_and_deletes_and_redaction_drops_unapproved_fields(): void
    {
        $log = SecurityAuditLog::create(['request_id' => (string) \Illuminate\Support\Str::uuid(), 'category' => 'access', 'event' => 'audit.viewed', 'outcome' => 'success', 'method' => 'GET', 'occurred_at' => now()]);
        try { $log->update(['event' => 'changed']); $this->fail('Audit update was allowed.'); } catch (\LogicException $error) {}
        try { $log->delete(); $this->fail('Audit deletion was allowed.'); } catch (\LogicException $error) {}
        $this->assertSame('audit.viewed', $log->fresh()->event);
        $safe = SecurityAudit::clean(['password' => 'private', 'token' => 'private', 'remember_token' => 'private', 'proof_path' => 'private', 'status' => 'paid']);
        $this->assertSame(['status' => 'paid'], $safe);
    }

    public function test_archived_downloads_record_subject_without_recording_storage_paths(): void
    {
        $record = $this->record('water');
        $revision = \App\Models\RequestRevision::create(['module' => 'water', 'request_id' => $record->id, 'user_id' => $record->created_by,
            'action' => 'returned', 'reason' => 'Revision', 'before' => ['attachment_path' => 'private/bill.pdf', 'attachment_name' => 'bill.pdf'], 'after' => []]);
        $this->actingAs(User::find($record->created_by))->get(route('request-revisions.attachment', [$revision, 'before']))->assertDownload('bill.pdf');
        $log = SecurityAuditLog::where('event', 'attachment.download_requested')->firstOrFail();
        $this->assertSame('RequestRevision', $log->subject_type); $this->assertSame('water', $log->module); $this->assertSame($record->id, $log->record_id);
        $this->assertSame('before', $log->metadata['side']); $this->assertStringNotContainsString('private/bill.pdf', $log->toJson());
    }

    public function test_client_headers_cannot_forge_the_request_id_or_trusted_ip_and_actor_snapshots_survive_changes(): void
    {
        $actor = User::factory()->create(['role' => 'finance']);
        $this->actingAs($actor)->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])->withHeaders(['X-Request-ID' => 'FORGED-ID', 'X-Forwarded-For' => '1.2.3.4', 'User-Agent' => str_repeat('x', 700)])->getJson('/users/records')->assertForbidden();
        $log = SecurityAuditLog::where('event', 'access.denied')->firstOrFail();
        $this->assertSame('203.0.113.12', $log->ip_address); $this->assertNotSame('FORGED-ID', $log->request_id); $this->assertSame(500, strlen($log->user_agent));
        $actor->name = 'Renamed'; $actor->role = 'it'; $actor->save();
        $this->assertSame('finance', $log->fresh()->actor_role); $this->assertNotSame('Renamed', $log->fresh()->actor_name);
    }

    public function test_password_recovery_events_never_contain_the_reset_token_or_password(): void
    {
        $user = User::factory()->create(); $token = Password::createToken($user);
        $this->postJson('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'Private-Reset-Password-123', 'password_confirmation' => 'Private-Reset-Password-123'])->assertOk();
        $this->assertSame(1, SecurityAuditLog::where('event', 'auth.password_reset_completed')->count());
        $serialized = SecurityAuditLog::all()->toJson(); $this->assertStringNotContainsString($token, $serialized); $this->assertStringNotContainsString('Private-Reset-Password-123', $serialized);
    }
}
