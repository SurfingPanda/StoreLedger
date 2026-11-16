<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\PosRenewal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosRenewalTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['store_name' => 'BW Makati', 'renewal_date' => '2027-02-15', 'amount' => '18000.50'];
    }

    public function test_guests_cannot_view_or_create_records(): void
    {
        $this->get('/utak-pos')->assertRedirect('/login');
        $this->getJson('/utak-pos/records')->assertUnauthorized();
        $this->postJson('/utak-pos/records', $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('pos_renewals', 0);
    }

    public function test_admin_can_create_a_persistent_renewal_for_accounting(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->postJson('/utak-pos/records', $this->payload() + ['status' => 'paid', 'created_by' => 999])
            ->assertCreated()->assertJsonPath('record.status', 'awaiting_voucher')->assertJsonPath('record.amount', '18000.50');
        $this->assertDatabaseHas('pos_renewals', $this->payload() + ['status' => 'awaiting_voucher', 'created_by' => $user->id]);
        $this->getJson('/utak-pos/records')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.store_name', 'BW Makati');
        $this->withoutVite()->get('/utak-pos')->assertOk()->assertViewHas('appData', fn ($data) => $data['page'] === 'utak-pos' && $data['user']['role'] === 'admin');
    }

    public function test_other_team_roles_cannot_create_admin_records(): void
    {
        foreach (['it', 'accounting', 'finance', 'rso'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->postJson('/utak-pos/records', $this->payload())->assertForbidden();
        }
        $this->assertDatabaseCount('pos_renewals', 0);
    }

    public function test_store_date_and_positive_two_decimal_amount_are_required(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/utak-pos/records', [])->assertUnprocessable()->assertJsonValidationErrors(['store_name', 'renewal_date', 'amount']);
        foreach (['0', '-1', '12.345', '10000000000', 'not money'] as $amount) {
            $this->postJson('/utak-pos/records', array_replace($this->payload(), ['amount' => $amount]))
                ->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $this->postJson('/utak-pos/records', array_replace($this->payload(), ['store_name' => '   ', 'renewal_date' => '2027-02-30']))
            ->assertUnprocessable()->assertJsonValidationErrors(['store_name', 'renewal_date']);
        $this->assertDatabaseCount('pos_renewals', 0);
    }

    public function test_search_and_pagination_return_saved_records_in_date_order(): void
    {
        $this->actingAs($user = User::factory()->create(['role' => 'admin']));
        for ($index = 1; $index <= 17; $index++) {
            $renewal = new PosRenewal(['store_name' => 'BW Store '.$index, 'renewal_date' => sprintf('2027-02-%02d', $index), 'amount' => '10.25']);
            $renewal->created_by = $user->id;
            $renewal->save();
        }
        $this->getJson('/utak-pos/records')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('total', 17)->assertJsonPath('data.0.store_name', 'BW Store 1');
        $this->getJson('/utak-pos/records?page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/utak-pos/records?search=Store%2017')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.store_name', 'BW Store 17');
    }
}
