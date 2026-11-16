<?php

namespace Tests\Feature;

use App\Models\BillingSoa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingWorkflowIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_category_keeps_its_records_totals_history_and_actions_separate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $accountant = User::factory()->create(['role' => 'accounting']);
        foreach (['water', 'electricity', 'rental'] as $index => $module) {
            $record = new BillingSoa(['store_name' => 'Same store', 'amount' => (string) (($index + 1) * 100), 'due_date' => '2026-12-01']);
            $record->module = $module;
            $record->created_by = $admin->id;
            $record->attachment_path = 'billing-soas/'.$module.'/soa.pdf';
            $record->attachment_name = 'soa.pdf';
            $record->save();
        }
        $this->actingAs($accountant);
        foreach (BillingSoa::all() as $record) {
            $this->getJson('/'.$record->module.'/records')->assertOk()->assertJsonPath('total', 1)
                ->assertJsonPath('summary.pending_count', 1)->assertJsonPath('summary.pending_amount', $record->amount);
            $this->getJson('/'.$record->module.'/records/'.$record->id.'/details')->assertOk()->assertJsonPath('history.total', 1);
            foreach (array_diff(['water', 'electricity', 'rental'], [$record->module]) as $other) {
                $base = '/'.$other.'/records/'.$record->id;
                $this->get($base)->assertNotFound();
                $this->getJson($base.'/details')->assertNotFound();
                $this->getJson($base.'/payment-proof')->assertNotFound();
                $this->postJson($base.'/comments', ['body' => 'Wrong category'])->assertNotFound();
                $this->postJson($base.'/voucher', ['action' => 'process'])->assertNotFound();
                $this->postJson($base.'/payment', [])->assertNotFound();
            }
            $this->assertSame('awaiting_voucher', $record->fresh()->status);
        }
        $this->assertDatabaseCount('billing_soa_comments', 0);
        $this->assertDatabaseCount('billing_soa_payments', 0);
    }
}
