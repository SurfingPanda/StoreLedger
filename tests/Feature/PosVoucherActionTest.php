<?php

namespace Tests\Feature;

use App\Models\PosRenewal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PosVoucherActionTest extends TestCase
{
    use RefreshDatabase;

    private function renewal(): PosRenewal
    {
        $record = new PosRenewal(['store_name' => 'BW Makati', 'renewal_date' => now('Asia/Manila')->addDays(5)->toDateString(), 'amount' => '1500.00']);
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        $record->save();
        return $record;
    }

    public static function actions(): array
    {
        return [['process', 'vouchered'], ['cancel', 'cancelled']];
    }

    #[DataProvider('actions')]
    public function test_accounting_can_resolve_a_pending_renewal_once(string $action, string $status): void
    {
        $record = $this->renewal();
        $accountant = User::factory()->create(['role' => 'accounting']);
        $url = route('utak-pos.voucher', $record);
        $this->actingAs($accountant)->postJson($url, ['voucher_reference' => 'TEST-VOUCHER', 'cancellation_reason' => 'Reviewed and cancelled by Accounting.', 'action' => $action, 'voucher_action_by' => 999])
            ->assertOk()->assertJsonPath('record.status', $status)->assertJsonPath('record.voucher_action_by', $accountant->id);
        $record->refresh();
        $this->assertNotNull($record->voucher_action_at);
        $this->assertSame($status, $record->status);
        $this->getJson('/utak-pos/records')->assertJsonPath('total', 0);
        $other = User::factory()->create(['role' => 'accounting']);
        $this->actingAs($other)->postJson($url, ['voucher_reference' => 'TEST-VOUCHER', 'cancellation_reason' => 'Reviewed and cancelled by Accounting.', 'action' => $action === 'process' ? 'cancel' : 'process'])->assertConflict();
        $this->assertDatabaseHas('pos_renewals', ['id' => $record->id, 'status' => $status, 'voucher_action_by' => $accountant->id]);
        $this->actingAs(User::find($record->created_by))->getJson('/utak-pos/records')->assertJsonPath('data.0.status', $status);
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $this->artisan('pos:send-renewal-reminders')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_guests_and_other_roles_cannot_resolve_renewals(): void
    {
        $record = $this->renewal();
        $url = route('utak-pos.voucher', $record);
        $this->postJson($url, ['action' => 'process', 'voucher_reference' => 'TEST-VOUCHER'])->assertUnauthorized();
        foreach (['admin', 'finance', 'rso', 'it'] as $role) {
            foreach (['process', 'cancel'] as $action) {
                $this->actingAs(User::factory()->create(['role' => $role]))->postJson($url, ['voucher_reference' => 'TEST-VOUCHER', 'cancellation_reason' => 'Reviewed and cancelled by Accounting.', 'action' => $action])->assertForbidden();
            }
        }
        $this->assertDatabaseHas('pos_renewals', ['id' => $record->id, 'status' => 'awaiting_voucher', 'voucher_action_by' => null, 'voucher_action_at' => null]);
    }

    public function test_invalid_actions_and_missing_renewals_are_rejected(): void
    {
        $record = $this->renewal();
        $this->actingAs(User::factory()->create(['role' => 'accounting']));
        foreach ([[], ['action' => 'paid'], ['action' => ['process']]] as $payload) {
            $this->postJson(route('utak-pos.voucher', $record), $payload)->assertUnprocessable()->assertJsonValidationErrors('action');
        }
        $this->postJson('/utak-pos/records/999999/voucher', ['action' => 'process', 'voucher_reference' => 'TEST-VOUCHER'])->assertNotFound();
        $this->assertDatabaseHas('pos_renewals', ['id' => $record->id, 'status' => 'awaiting_voucher', 'voucher_action_at' => null]);
    }
}
