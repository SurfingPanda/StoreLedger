<?php

namespace Tests\Feature;

use App\Models\PosRenewal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_totals_include_all_records_independently_of_search_status_and_page(): void
    {
        $this->actingAs($admin = User::factory()->create(['role' => 'admin']));
        foreach ([['awaiting_voucher', '100.25', -1], ['awaiting_voucher', '200.50', 1], ['vouchered', '300.75', 0], ['paid', '400.00', -1], ['cancelled', '999.00', -1]] as [$status, $amount, $offset]) {
            $record = new PosRenewal(['store_name' => 'Store '.$status, 'amount' => $amount, 'renewal_date' => now('Asia/Manila')->addDays($offset)->toDateString()]);
            $record->created_by = $admin->id;
            $record->status = $status;
            $record->save();
        }
        $this->actingAs(User::factory()->create(['role' => 'accounting']));
        foreach (['', '?status=paid', '?search=NotFound', '?page=2'] as $query) {
            $this->getJson('/utak-pos/records'.$query)->assertOk()
                ->assertJsonPath('summary.pending_count', 2)->assertJsonPath('summary.pending_amount', '300.75')
                ->assertJsonPath('summary.awaiting_payment_count', 1)->assertJsonPath('summary.awaiting_payment_amount', '300.75')
                ->assertJsonPath('summary.paid_count', 1)->assertJsonPath('summary.paid_amount', '400.00')
                ->assertJsonPath('summary.overdue_pending_count', 1);
        }
    }

    public function test_sorting_orders_all_records_before_pagination_and_validates_columns(): void
    {
        $this->actingAs($admin = User::factory()->create(['role' => 'admin']));
        $ids = [];
        foreach ([['Zulu', '9.00', '2026-12-03', 'paid'], ['Alpha', '100.00', '2026-12-01', 'awaiting_voucher'], ['Beta', '20.00', '2026-12-02', 'vouchered']] as $index => [$store, $amount, $date, $status]) {
            $record = new PosRenewal(['store_name' => $store, 'amount' => $amount, 'renewal_date' => $date]);
            $record->status = $status;
            $record->created_by = $admin->id;
            $record->created_at = '2026-11-'.(20 + $index).' 10:00:00';
            $record->save();
            $ids[] = $record->id;
        }
        foreach (['id' => [0, 1, 2], 'store_name' => [1, 2, 0], 'amount' => [0, 2, 1], 'renewal_date' => [1, 2, 0], 'status' => [1, 2, 0], 'created_at' => [0, 1, 2]] as $sort => $order) {
            $expected = array_map(fn ($index) => $ids[$index], $order);
            foreach (['asc', 'desc'] as $direction) {
                $response = $this->getJson('/utak-pos/records?status=all&sort='.$sort.'&direction='.$direction)->assertOk();
                $this->assertSame($direction === 'asc' ? $expected : array_reverse($expected), array_column($response->json('data'), 'id'));
            }
        }
        $this->getJson('/utak-pos/records?sort=amount&direction=desc&search=Beta')->assertJsonPath('total', 1)->assertJsonPath('data.0.store_name', 'Beta');
        $this->getJson('/utak-pos/records?sort=password')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/utak-pos/records?direction=invalid')->assertUnprocessable()->assertJsonValidationErrors('direction');
        foreach (range(1, 16) as $index) {
            $record = new PosRenewal(['store_name' => 'Extra '.$index, 'amount' => (string) (100 + $index), 'renewal_date' => '2026-12-01']);
            $record->created_by = $admin->id;
            $record->save();
        }
        $this->getJson('/utak-pos/records?sort=amount&direction=desc')->assertJsonCount(15, 'data')->assertJsonPath('data.0.amount', '116.00');
        $this->getJson('/utak-pos/records?sort=amount&direction=desc&page=2')->assertJsonCount(4, 'data')->assertJsonPath('data.3.amount', '9.00');
    }

    public function test_accounting_and_finance_can_filter_across_workflow_stages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['awaiting_voucher', 'vouchered', 'paid', 'cancelled'] as $status) {
            $record = new PosRenewal(['store_name' => 'Store '.$status, 'amount' => '1500.00', 'renewal_date' => '2026-12-01']);
            $record->created_by = $admin->id;
            $record->status = $status;
            $record->save();
        }
        foreach (['accounting', 'finance'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/utak-pos/records')->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.status', $role === 'accounting' ? 'awaiting_voucher' : 'vouchered');
            foreach (['awaiting_voucher', 'vouchered', 'paid', 'cancelled'] as $status) {
                $this->getJson('/utak-pos/records?status='.$status)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.status', $status);
            }
            $this->getJson('/utak-pos/records?status=all')->assertJsonPath('total', 4);
            $this->getJson('/utak-pos/records?status=processed')->assertJsonPath('total', 2);
            $this->getJson('/utak-pos/records?status=processed&search=Store%20paid')->assertJsonPath('total', 1)->assertJsonPath('data.0.status', 'paid');
            $this->getJson('/utak-pos/records?status=processed&page=2')->assertJsonCount(0, 'data')->assertJsonPath('total', 2);
            $this->getJson('/utak-pos/records?status=unknown')->assertUnprocessable()->assertJsonValidationErrors('status');
        }
    }
}
