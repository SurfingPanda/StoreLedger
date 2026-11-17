<?php

namespace Tests\Feature;

use App\Models\BillingSoa;
use App\Models\InternetSoa;
use App\Models\PosRenewal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function record(string $module, string $status, string $amount, int $offset = 0): Model
    {
        $this->actingAs($user = User::factory()->create(['role' => 'admin']));
        $record = match ($module) {
            'utak-pos' => new PosRenewal(), 'internet-soa' => new InternetSoa(), default => new BillingSoa(),
        };
        $record->store_name = 'BW '.$module;
        $record->amount = $amount;
        $record->status = $status;
        $record->created_by = $user->id;
        $dateField = $module === 'utak-pos' ? 'renewal_date' : 'due_date';
        $record->$dateField = now('Asia/Manila')->addDays($offset)->toDateString();
        if ($module !== 'utak-pos') {
            $record->attachment_path = 'private/example.pdf';
            $record->attachment_name = 'example.pdf';
        }
        if ($record instanceof BillingSoa) $record->module = $module;
        if ($status !== 'awaiting_voucher') {
            $record->voucher_action_at = now();
            $record->voucher_action_by = $user->id;
        }
        $record->save();
        if ($status === 'paid') {
            $payment = $record->payment()->make(['paid_on' => now()->toDateString(), 'method' => 'cash', 'reference' => 'PAY-1']);
            $payment->amount = $amount;
            $payment->user_id = $user->id;
            $payment->proof_path = 'private/proof.pdf';
            $payment->proof_name = 'proof.pdf';
            $payment->save();
        }
        return $record;
    }

    public function test_dashboard_combines_all_five_modules_and_excludes_paid_and_cancelled_from_unpaid_totals(): void
    {
        $this->record('utak-pos', 'awaiting_voucher', '100.25', -1);
        $this->record('internet-soa', 'vouchered', '200.50', 1);
        $this->record('water', 'paid', '300.75', -2);
        $this->record('electricity', 'cancelled', '999.00', -1);
        $this->record('rental', 'awaiting_voucher', '400.25');
        $response = $this->getJson('/dashboard/records')->assertOk()
            ->assertJsonPath('summary.total_count', 5)->assertJsonPath('summary.pending_count', 2)
            ->assertJsonPath('summary.awaiting_payment_count', 1)->assertJsonPath('summary.paid_count', 1)
            ->assertJsonPath('summary.outstanding_amount', '701.00')->assertJsonPath('summary.overdue_count', 1)
            ->assertJsonPath('summary.overdue_amount', '100.25')->assertJsonCount(5, 'records.data')
            ->assertJsonCount(3, 'due')->assertJsonCount(4, 'breakdown')
            ->assertJsonPath('due.0.module', 'utak-pos')->assertJsonPath('due.1.module', 'rental');
        $keys = array_column($response->json('records.data'), 'key');
        $this->assertCount(5, array_unique($keys));
        foreach ($response->json('records.data') as $row) {
            $this->assertSame(route($row['module'].'.detail', $row['id']), $row['detail_url']);
            $this->assertArrayNotHasKey('attachment_path', $row);
        }
    }

    public function test_filters_and_pagination_use_saved_requests_and_summary_remains_independent_of_register_filters(): void
    {
        $this->record('water', 'awaiting_voucher', '50.25', -40);
        $this->record('water', 'paid', '60.00', 1);
        $this->record('rental', 'awaiting_voucher', '999.00', 1);
        $this->getJson('/dashboard/records?module=water&period=30')->assertJsonPath('summary.total_count', 1)
            ->assertJsonPath('summary.outstanding_amount', '0.00')->assertJsonPath('records.data.0.status', 'paid');
        $this->getJson('/dashboard/records?module=water&status=paid&search=NotFound')->assertJsonPath('summary.total_count', 2)
            ->assertJsonPath('summary.outstanding_amount', '50.25')->assertJsonPath('records.total', 0);
        $this->getJson('/dashboard/records?search=rental&status=awaiting_voucher')->assertJsonPath('records.total', 1)->assertJsonPath('records.data.0.module', 'rental');
        for ($i = 0; $i < 16; $i++) $this->record('utak-pos', 'awaiting_voucher', '10.00');
        $this->getJson('/dashboard/records?module=utak-pos')->assertJsonCount(15, 'records.data')->assertJsonPath('records.total', 16);
        $this->getJson('/dashboard/records?module=utak-pos&page=2')->assertJsonCount(1, 'records.data');
        $this->getJson('/dashboard/records?module=invalid')->assertUnprocessable();
        $this->getJson('/dashboard/records?page=0')->assertUnprocessable();
    }

    public function test_activity_shows_real_creation_voucher_cancellation_and_payment_events(): void
    {
        $this->record('utak-pos', 'awaiting_voucher', '100.00');
        $this->record('internet-soa', 'vouchered', '200.00');
        $this->record('electricity', 'cancelled', '300.00');
        $this->record('rental', 'paid', '400.00');
        $response = $this->getJson('/dashboard/activity')->assertOk()->assertJsonCount(8, 'events');
        $names = array_column($response->json('events'), 'event');
        $this->assertContains('Request created', $names);
        $this->assertContains('Voucher processed', $names);
        $this->assertContains('Request cancelled', $names);
        $this->assertContains('Payment recorded', $names);
    }

    public function test_guests_are_blocked_and_empty_database_returns_actual_zero_totals(): void
    {
        $this->getJson('/dashboard/records')->assertUnauthorized();
        $this->getJson('/dashboard/activity')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'accounting']));
        $this->getJson('/dashboard/records')->assertOk()->assertJsonPath('summary.total_count', 0)
            ->assertJsonPath('summary.outstanding_amount', '0.00')->assertJsonPath('records.total', 0)->assertJsonCount(0, 'due');
        $this->getJson('/dashboard/activity')->assertOk()->assertJsonCount(0, 'events');
        $this->withoutVite()->get('/dashboard')->assertOk();
    }
}
