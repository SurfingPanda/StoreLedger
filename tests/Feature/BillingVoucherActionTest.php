<?php

namespace Tests\Feature;

use App\Models\BillingSoa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingVoucherActionTest extends TestCase
{
    use RefreshDatabase;
    private string $module = 'water';

    public static function modules(): array
    {
        return [['water'], ['electricity'], ['rental']];
    }


    private function renewal(): BillingSoa
    {
        $record = new BillingSoa(['store_name' => 'BW Makati', 'due_date' => now('Asia/Manila')->addDays(5)->toDateString(), 'amount' => '1500.00']);
        $record->module = $this->module;
        $record->attachment_path = 'internet-soas/statement.pdf';
        $record->attachment_name = 'statement.pdf';
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        $record->save();
        return $record;
    }

    public static function actions(): array
    {
        return [['water', 'process', 'vouchered'], ['water', 'cancel', 'cancelled'], ['electricity', 'process', 'vouchered'], ['electricity', 'cancel', 'cancelled'], ['rental', 'process', 'vouchered'], ['rental', 'cancel', 'cancelled']];
    }

    #[DataProvider('actions')]
    public function test_accounting_can_resolve_a_pending_renewal_once(string $module, string $action, string $status): void
    {
        $this->module = $module;
        $record = $this->renewal();
        $accountant = User::factory()->create(['role' => 'accounting']);
        $url = route($this->module.'.voucher', $record);
        $this->actingAs($accountant)->postJson($url, ['voucher_reference' => 'TEST-VOUCHER', 'cancellation_reason' => 'Reviewed and cancelled by Accounting.', 'action' => $action, 'voucher_action_by' => 999])
            ->assertOk()->assertJsonPath('record.status', $status)->assertJsonPath('record.voucher_action_by', $accountant->id);
        $record->refresh();
        $this->assertNotNull($record->voucher_action_at);
        $this->assertSame($status, $record->status);
        $this->getJson('/'.$this->module.'/records')->assertJsonPath('total', 0);
        $other = User::factory()->create(['role' => 'accounting']);
        $this->actingAs($other)->postJson($url, ['voucher_reference' => 'TEST-VOUCHER', 'cancellation_reason' => 'Reviewed and cancelled by Accounting.', 'action' => $action === 'process' ? 'cancel' : 'process'])->assertConflict();
        $this->assertDatabaseHas('billing_soas', ['id' => $record->id, 'status' => $status, 'voucher_action_by' => $accountant->id]);
        $this->actingAs(User::find($record->created_by))->getJson('/'.$this->module.'/records')->assertJsonPath('data.0.status', $status);
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $this->artisan('billing:send-soa-reminders')->assertSuccessful();
        Notification::assertNothingSent();
    }

    #[DataProvider('modules')]
    public function test_guests_and_other_roles_cannot_resolve_renewals(string $module): void
    {
        $this->module = $module;
        $record = $this->renewal();
        $url = route($this->module.'.voucher', $record);
        $this->postJson($url, ['action' => 'process', 'voucher_reference' => 'TEST-VOUCHER'])->assertUnauthorized();
        foreach (['admin', 'finance', 'rso', 'it'] as $role) {
            foreach (['process', 'cancel'] as $action) {
                $this->actingAs(User::factory()->create(['role' => $role]))->postJson($url, ['voucher_reference' => 'TEST-VOUCHER', 'cancellation_reason' => 'Reviewed and cancelled by Accounting.', 'action' => $action])->assertForbidden();
            }
        }
        $this->assertDatabaseHas('billing_soas', ['id' => $record->id, 'status' => 'awaiting_voucher', 'voucher_action_by' => null, 'voucher_action_at' => null]);
    }

    #[DataProvider('modules')]
    public function test_invalid_actions_and_missing_renewals_are_rejected(string $module): void
    {
        $this->module = $module;
        $record = $this->renewal();
        $this->actingAs(User::factory()->create(['role' => 'accounting']));
        foreach ([[], ['action' => 'paid'], ['action' => ['process']]] as $payload) {
            $this->postJson(route($this->module.'.voucher', $record), $payload)->assertUnprocessable()->assertJsonValidationErrors('action');
        }
        $this->postJson('/'.$this->module.'/records/999999/voucher', ['action' => 'process', 'voucher_reference' => 'TEST-VOUCHER'])->assertNotFound();
        $this->assertDatabaseHas('billing_soas', ['id' => $record->id, 'status' => 'awaiting_voucher', 'voucher_action_at' => null]);
    }
}
