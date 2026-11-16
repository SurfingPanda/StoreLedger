<?php

namespace Tests\Feature;

use App\Models\BillingSoa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BillingPaymentTest extends TestCase
{
    use RefreshDatabase;
    private string $module = 'water';

    public static function modules(): array
    {
        return [['water'], ['electricity'], ['rental']];
    }


    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function renewal(string $status = 'vouchered'): BillingSoa
    {
        $record = new BillingSoa(['store_name' => 'BW Makati', 'amount' => '1500.00', 'due_date' => now()->toDateString()]);
        $record->module = $this->module;
        $record->attachment_path = 'internet-soas/statement.pdf';
        $record->attachment_name = 'statement.pdf';
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        $record->status = $status;
        $record->save();
        return $record;
    }

    private function payload(): array
    {
        return ['paid_on' => now()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'BANK-12345', 'notes' => 'Paid through the company bank account.',
            'proof' => UploadedFile::fake()->create('receipt.pdf', 30, 'application/pdf')];
    }

    #[DataProvider('modules')]
    public function test_processed_vouchers_move_to_finance_and_payment_retains_proof_and_history(string $module): void
    {
        $this->module = $module;
        $record = $this->renewal('awaiting_voucher');
        $this->actingAs(User::factory()->create(['role' => 'finance']))->getJson('/'.$this->module.'/records')->assertJsonPath('total', 0);
        $this->actingAs($accountant = User::factory()->create(['role' => 'accounting']))
            ->postJson(route($this->module.'.voucher', $record), ['action' => 'process', 'voucher_reference' => 'VCH-1'])->assertOk();
        $this->actingAs($finance = User::factory()->create(['role' => 'finance']));
        $this->getJson('/'.$this->module.'/records')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $record->id);
        $this->post(route($this->module.'.payment', $record), $this->payload() + ['amount' => '1.00', 'user_id' => $accountant->id], ['Accept' => 'application/json'])->assertCreated();
        $record->refresh();
        $this->assertSame('paid', $record->status);
        $payment = $record->payment;
        $this->assertSame('1500.00', $payment->amount);
        $this->assertSame($finance->id, $payment->user_id);
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->getJson('/'.$this->module.'/records')->assertJsonPath('total', 0);
        $this->getJson(route($this->module.'.details', $record))->assertOk()->assertJsonPath('record.payment.reference', 'BANK-12345')
            ->assertJsonPath('record.payment.recorded_by.name', $finance->name)->assertJsonPath('record.voucher_reference', 'VCH-1')
            ->assertJsonMissingPath('record.payment.proof_path')
            ->assertJsonPath('history.data.0.voucher_reference', 'VCH-1')
            ->assertJsonPath('history.data.0.voucher_actor.name', $accountant->name)
            ->assertJsonPath('history.data.0.payment.paid_on', now()->toDateString())
            ->assertJsonPath('history.data.0.payment.reference', 'BANK-12345')
            ->assertJsonPath('history.data.0.payment.recorded_by.name', $finance->name)
            ->assertJsonPath('history.data.0.payment.proof_url', route($this->module.'.payment-proof', $record))
            ->assertJsonMissingPath('history.data.0.payment.proof_path');
        $this->get(route($this->module.'.payment-proof', $record))->assertOk()->assertDownload('receipt.pdf');
        $this->postJson(route($this->module.'.comments', $record), ['body' => 'Payment confirmed.'])->assertCreated();
        $this->post(route($this->module.'.payment', $record), $this->payload(), ['Accept' => 'application/json'])->assertConflict();
        $this->assertDatabaseCount('billing_soa_payments', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    #[DataProvider('modules')]
    public function test_only_finance_can_pay_and_only_processed_vouchers_are_eligible(string $module): void
    {
        $this->module = $module;
        $record = $this->renewal();
        $this->postJson(route($this->module.'.payment', $record), [])->assertUnauthorized();
        $this->getJson(route($this->module.'.payment-proof', $record))->assertUnauthorized();
        foreach (['admin', 'accounting', 'it', 'rso'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->postJson(route($this->module.'.payment', $record), [])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'finance']));
        foreach (['awaiting_voucher', 'cancelled', 'paid'] as $status) {
            $other = $this->renewal($status);
            $this->post(route($this->module.'.payment', $other), $this->payload(), ['Accept' => 'application/json'])->assertConflict();
        }
        $this->assertDatabaseCount('billing_soa_payments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->get(route($this->module.'.payment-proof', $record))->assertNotFound();
    }

    #[DataProvider('modules')]
    public function test_payment_details_and_proof_are_required_and_validated(string $module): void
    {
        $this->module = $module;
        $record = $this->renewal();
        $this->actingAs(User::factory()->create(['role' => 'finance']));
        $this->postJson(route($this->module.'.payment', $record), [])->assertUnprocessable()->assertJsonValidationErrors(['paid_on', 'method', 'reference', 'proof']);
        foreach ([
            ['paid_on' => now()->addDay()->toDateString()],
            ['paid_on' => '2026-02-30'],
            ['method' => 'invalid'],
            ['reference' => '  '],
            ['notes' => str_repeat('x', 5001)],
            ['proof' => UploadedFile::fake()->create('receipt.txt', 1, 'text/plain')],
            ['proof' => UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf')],
        ] as $invalid) {
            $this->post(route($this->module.'.payment', $record), array_replace($this->payload(), $invalid), ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
        }
        $this->assertSame('vouchered', $record->fresh()->status);
        $this->assertDatabaseCount('billing_soa_payments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
