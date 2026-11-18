<?php
namespace Tests\Feature;

use App\Models\User;
use App\Models\BillingSoa;
use App\Models\SecurityAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RsoSubmissionTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Storage::fake('local'); }
    public static function modules(): array { return [['rental'], ['electricity'], ['water']]; }
    private function payload(): array
    {
        return ['store_name' => 'BW RSO Store', 'provider' => 'Provider', 'billing_period' => '2026-11', 'amount' => '1500.25',
            'due_date' => '2026-12-15', 'soa' => UploadedFile::fake()->create('soa.pdf', 2, 'application/pdf')];
    }

    #[DataProvider('modules')]
    public function test_rso_can_submit_soas_with_correct_creator_and_audit_but_cannot_voucher_or_pay(string $module): void
    {
        $rso = User::factory()->create(['role' => 'rso']); $this->actingAs($rso);
        $this->post('/'.$module.'/records', $this->payload(), ['Accept' => 'application/json'])->assertCreated();
        $record = BillingSoa::firstOrFail();
        $this->assertSame($rso->id, $record->created_by); $this->assertSame('awaiting_voucher', $record->status);
        Storage::disk('local')->assertExists($record->attachment_path);
        $this->assertSame('rso', SecurityAuditLog::where('event', 'request.created')->firstOrFail()->actor_role);
        $this->postJson(route($module.'.voucher', $record), ['action' => 'process'])->assertForbidden();
        $this->postJson(route($module.'.payment', $record), [])->assertForbidden();
        $this->postJson('/directory/stores', [])->assertForbidden();
    }

    #[DataProvider('modules')]
    public function test_rso_can_attach_current_statement_for_recurring_requests(string $module): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $record = new BillingSoa(['store_name' => 'Recurring store', 'amount' => '1500', 'due_date' => '2026-12-15']);
        $record->module = $module; $record->status = 'awaiting_soa'; $record->created_by = $admin->id; $record->save();
        foreach (['it', 'accounting', 'finance'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->post(route($module.'.statement', $record), ['amount' => 1600, 'soa' => UploadedFile::fake()->create('statement.pdf', 2, 'application/pdf')], ['Accept' => 'application/json'])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'rso']))->post(route($module.'.statement', $record), ['amount' => 1600, 'soa' => UploadedFile::fake()->create('statement.pdf', 2, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('awaiting_voucher', $record->fresh()->status);
    }

    #[DataProvider('modules')]
    public function test_rso_can_correct_own_returned_submission_but_losing_role_revokes_submission_access(string $module): void
    {
        $rso = User::factory()->create(['role' => 'rso']); $this->actingAs($rso)->post('/'.$module.'/records', $this->payload(), ['Accept' => 'application/json'])->assertCreated();
        $record = BillingSoa::firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'accounting']))->postJson('/'.$module.'/records/'.$record->id.'/return', ['workflow_version' => $record->workflow_version, 'target' => 'requester', 'reason' => 'Correct amount.'])->assertOk();
        $rso->role = 'finance'; $rso->save();
        $correction = ['workflow_version' => $record->fresh()->workflow_version, 'amount' => 1800, 'due_date' => '2026-12-15', 'billing_period' => '2026-11', 'correction_note' => 'Corrected the amount.'];
        $this->actingAs($rso->fresh())->postJson('/'.$module.'/records/'.$record->id.'/resubmit', $correction)->assertForbidden();
        $rso->role = 'rso'; $rso->save();
        $this->actingAs($rso->fresh())->postJson('/'.$module.'/records/'.$record->id.'/resubmit', $correction)->assertOk();
        $this->assertSame('1800.00', $record->fresh()->amount);
    }

    public function test_rso_submission_access_does_not_extend_to_internet_or_pos_and_disabled_rso_is_blocked(): void
    {
        $rso = User::factory()->create(['role' => 'rso']); $this->actingAs($rso);
        $this->post('/internet-soa/records', $this->payload(), ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson('/utak-pos/records', ['store_name' => 'Test', 'amount' => 100, 'renewal_date' => '2026-12-15'])->assertForbidden();
        $rso->is_active = false; $rso->save();
        $this->actingAs($rso->fresh())->post('/water/records', $this->payload(), ['Accept' => 'application/json'])->assertUnauthorized();
        $this->assertDatabaseCount('billing_soas', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
