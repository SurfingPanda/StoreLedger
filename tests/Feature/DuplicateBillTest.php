<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RequestModule;
use App\Services\DuplicateBills;
use App\Services\RecurringBilling;
use App\Models\RecurringSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DuplicateBillTest extends TestCase
{
    use RefreshDatabase;

    public static function modules(): array
    {
        return array_map(fn ($module) => [$module], array_keys(RequestModule::LABELS));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function payload(string $module, array $overrides = []): array
    {
        return array_replace([
            'store_name' => 'BW Makati', 'provider' => 'Sample Provider', 'billing_period' => '2026-11',
            RequestModule::dateField($module) => '2026-12-10', 'amount' => '1500.00',
        ], $module === 'utak-pos' ? [] : ['soa' => UploadedFile::fake()->create('bill.pdf', 2, 'application/pdf')], $overrides);
    }

    private function legacy(string $module, array $attributes = [])
    {
        $record = RequestModule::query($module)->getModel()->newInstance($this->payload($module));
        unset($record->soa);
        if (!in_array($module, ['utak-pos', 'internet-soa'])) $record->module = $module;
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        $record->status = 'awaiting_voucher';
        $record->forceFill($attributes)->save();
        return $record;
    }

    #[DataProvider('modules')]
    public function test_repeated_bills_are_rejected_before_saving_with_links_and_no_orphan_files(string $module): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/'.$module.'/records', $this->payload($module), ['Accept' => 'application/json'])->assertCreated();
        $record = RequestModule::query($module)->firstOrFail();
        $before = Storage::disk('local')->allFiles();
        $this->post('/'.$module.'/records', $this->payload($module, [
            'store_name' => ' bw   MAKATI ', 'provider' => ' SAMPLE   provider ', 'amount' => '1999.00',
            RequestModule::dateField($module) => '2027-01-10',
        ]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('duplicate_bill')
            ->assertJsonPath('duplicates.0.detail_url', route($module.'.detail', $record));
        $this->assertSame(1, RequestModule::query($module)->count());
        $this->assertEquals($before, Storage::disk('local')->allFiles());
    }

    #[DataProvider('modules')]
    public function test_other_providers_periods_stores_and_cancelled_bills_are_allowed(string $module): void
    {
        $record = $this->legacy($module);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([['provider' => 'Other provider'], ['billing_period' => '2026-10'], ['store_name' => 'BW Other']] as $change) {
            $this->post('/'.$module.'/records', $this->payload($module, $change), ['Accept' => 'application/json'])->assertCreated();
        }
        $record->status = 'cancelled'; $record->save();
        $this->post('/'.$module.'/records', $this->payload($module), ['Accept' => 'application/json'])->assertCreated();
    }

    #[DataProvider('modules')]
    public function test_existing_duplicates_are_flagged_and_cannot_be_vouchered_until_cancelled(string $module): void
    {
        $record = $this->legacy($module);
        $other = $this->legacy($module);
        $this->actingAs(User::factory()->create(['role' => 'accounting']));
        $this->getJson('/'.$module.'/records')->assertJsonPath('data.0.duplicate_count', 1);
        $this->getJson(route($module.'.details', $record))->assertJsonPath('duplicates.0.id', $other->id);
        $this->postJson(route($module.'.voucher', $record), ['action' => 'process'])->assertUnprocessable()->assertJsonValidationErrors('duplicate_bill');
        $this->assertSame('awaiting_voucher', $record->fresh()->status);
        $this->assertDatabaseCount('workflow_mail_deliveries', 0);
        $this->postJson(route($module.'.voucher', $other), ['action' => 'cancel'])->assertOk();
        $this->postJson(route($module.'.voucher', $record), ['action' => 'process'])->assertOk();
    }

    #[DataProvider('modules')]
    public function test_finance_cannot_pay_a_duplicate_of_an_already_paid_bill(string $module): void
    {
        $record = $this->legacy($module, ['status' => 'vouchered']);
        $this->legacy($module, ['status' => 'paid']);
        $this->actingAs(User::factory()->create(['role' => 'finance']));
        $this->post(route($module.'.payment', $record), [
            'paid_on' => now()->toDateString(), 'method' => 'cash', 'reference' => 'TEST-REF',
            'proof' => UploadedFile::fake()->create('proof.pdf', 2, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('duplicate_bill');
        $this->assertSame('vouchered', $record->fresh()->status);
        $this->assertNull($record->fresh()->payment);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[DataProvider('modules')]
    public function test_legacy_dates_and_unknown_provider_are_checked_and_admin_can_correct_with_audit_note(string $module): void
    {
        $record = $this->legacy($module, ['billing_period' => null, 'provider' => null]);
        $other = $this->legacy($module, ['billing_period' => '2026-12']);
        $this->assertCount(1, DuplicateBills::matches($record));
        $url = '/'.$module.'/records/'.$record->id.'/billing-metadata';
        $this->actingAs(User::factory()->create(['role' => 'finance']))->postJson($url, ['provider' => 'Different', 'billing_period' => '2026-12'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson($url, ['provider' => 'Different', 'billing_period' => '2026-13'])->assertUnprocessable();
        $this->postJson($url, ['provider' => 'Different', 'billing_period' => '2026-12'])->assertOk();
        $this->assertCount(0, DuplicateBills::matches($record->fresh()));
        $this->assertStringContainsString('Billing details corrected', $record->comments()->first()->body);
        $other->status = 'paid'; $other->save();
        $this->postJson('/'.$module.'/records/'.$other->id.'/billing-metadata', ['provider' => 'Different', 'billing_period' => '2026-12'])->assertConflict();
    }

    #[DataProvider('modules')]
    public function test_vouchered_duplicates_can_be_cancelled_without_losing_voucher_history(string $module): void
    {
        $accountant = User::factory()->create(['role' => 'accounting']);
        $record = $this->legacy($module, ['status' => 'vouchered', 'voucher_reference' => 'ORIGINAL-123',
            'voucher_action_by' => $accountant->id, 'voucher_action_at' => now()]);
        $other = $this->legacy($module, ['status' => 'vouchered']);
        $url = '/'.$module.'/records/'.$record->id.'/duplicate-cancel';
        $this->actingAs(User::factory()->create(['role' => 'finance']))->postJson($url)->assertForbidden();
        $this->actingAs($accountant)->postJson($url)->assertOk();
        $this->assertSame('cancelled', $record->fresh()->status);
        $this->assertNotNull($record->fresh()->duplicate_cancelled_at);
        $this->assertSame('ORIGINAL-123', $record->fresh()->voucher_reference);
        $this->assertSame($accountant->id, $record->fresh()->voucher_action_by);
        $this->assertStringContainsString('cancelled after duplicate-bill review', $record->comments()->first()->body);
        $this->postJson('/'.$module.'/records/'.$other->id.'/duplicate-cancel')->assertConflict();
        $this->assertCount(0, DuplicateBills::matches($other));
    }

    public function test_modules_do_not_cross_match_and_recurring_cycles_skip_manual_bills(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-12-05'));
        $source = $this->legacy('water');
        $this->legacy('electricity');
        $this->assertCount(0, DuplicateBills::matches($source));
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson(route('water.recurrence', $source), [
            'active' => true, 'interval_months' => 1, 'lead_days' => 15, 'amount' => '1500',
        ])->assertOk();
        $manual = $this->legacy('water', ['billing_period' => '2026-12', 'due_date' => '2027-01-10']);
        $schedule = RecurringSchedule::firstOrFail();
        $this->travelTo(\Carbon\Carbon::parse('2027-01-01'));
        $this->assertSame(0, RecurringBilling::generate($schedule->id));
        $this->assertSame('2027-02-10', $schedule->fresh()->next_due_date->toDateString());
        $this->travelTo(\Carbon\Carbon::parse('2027-02-01'));
        $this->assertSame(1, RecurringBilling::generate($schedule->id));
        $generated = RequestModule::query('water')->whereNotNull('recurring_schedule_id')->firstOrFail();
        $this->assertSame('Sample Provider', $generated->provider);
        $this->assertSame('2027-01', $generated->billing_period);
        $this->travelBack();
    }
}
