<?php

namespace Tests\Feature;

use App\Models\BillingSoa;
use App\Models\InternetSoa;
use App\Models\PosRenewal;
use App\Models\RecurringSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecurringBillingTest extends TestCase
{
    use RefreshDatabase;
    public static function modules(): array { return [['utak-pos'], ['internet-soa'], ['water'], ['electricity'], ['rental']]; }
    private function source(string $module, string $date = '2026-01-31')
    {
        $record = match ($module) { 'utak-pos' => new PosRenewal(), 'internet-soa' => new InternetSoa(), default => new BillingSoa() };
        $record->store_name = 'BW Test'; $record->amount = '1500.00';
        $field = $module === 'utak-pos' ? 'renewal_date' : 'due_date'; $record->$field = $date;
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        if ($module !== 'utak-pos') { $record->attachment_path = 'original-cycle.pdf'; $record->attachment_name = 'original-cycle.pdf'; }
        if ($record instanceof BillingSoa) $record->module = $module;
        $record->save(); $this->actingAs(User::find($record->created_by)); return $record;
    }
    private function config(bool $active = true): array { return ['active' => $active, 'interval_months' => 1, 'lead_days' => 15, 'amount' => '1500.00']; }

    #[DataProvider('modules')]
    public function test_generation_is_idempotent_and_soa_cycles_require_new_statement(string $module): void
    {
        Storage::fake('local'); $this->travelTo(now()->setDate(2026, 2, 13)->startOfDay());
        $source = $this->source($module);
        $this->postJson(route($module.'.recurrence', $source), $this->config())->assertOk()->assertJsonPath('recurrence.next_due_date', '2026-02-28');
        $this->artisan('billing:generate-recurring')->assertSuccessful();
        $this->artisan('billing:generate-recurring')->assertSuccessful();
        $next = $source->newQuery()->whereNotNull('recurring_schedule_id')->firstOrFail();
        $this->assertSame(2, $source->newQuery()->count());
        $this->assertSame($module === 'utak-pos' ? 'awaiting_voucher' : 'awaiting_soa', $next->status);
        if ($module !== 'utak-pos') {
            $this->assertNull($next->attachment_path);
            $this->actingAs(User::factory()->create(['role' => 'accounting']))->postJson(route($module.'.voucher', $next), ['action' => 'process'])->assertConflict();
            $this->actingAs(User::find($source->created_by))->post(route($module.'.statement', $next), [
                'amount' => '1675.25', 'soa' => UploadedFile::fake()->create('february.pdf', 20, 'application/pdf'),
            ], ['Accept' => 'application/json'])->assertOk();
            $next->refresh(); $this->assertSame('awaiting_voucher', $next->status); $this->assertSame('1675.25', $next->amount);
            Storage::disk('local')->assertExists($next->attachment_path);
            $this->assertSame('original-cycle.pdf', $source->fresh()->attachment_path);
        }
        $this->assertSame('2026-03-31', RecurringSchedule::first()->next_due_date->toDateString());
        $this->travelTo(now()->setDate(2026, 3, 16)->startOfDay());
        $this->artisan('billing:generate-recurring')->assertSuccessful();
        $this->assertSame(3, $source->newQuery()->count());
        $this->assertSame('2026-04-30', RecurringSchedule::first()->next_due_date->toDateString());
    }

    public function test_admin_can_pause_resume_and_change_cycle_without_recreating_old_requests(): void
    {
        $this->travelTo(now()->setDate(2026, 2, 13)->startOfDay()); $source = $this->source('utak-pos');
        $url = route('utak-pos.recurrence', $source);
        $this->postJson($url, $this->config(false))->assertOk(); $this->artisan('billing:generate-recurring')->assertSuccessful();
        $this->assertDatabaseCount('pos_renewals', 1);
        $this->postJson($url, $this->config())->assertOk(); $this->artisan('billing:generate-recurring')->assertSuccessful();
        $this->assertDatabaseCount('pos_renewals', 2);
        $this->postJson($url, array_replace($this->config(), ['interval_months' => 3]))->assertOk()->assertJsonPath('recurrence.next_due_date', '2026-05-28');
        $this->assertDatabaseCount('recurring_schedules', 1);
    }

    public function test_only_admin_can_configure_and_statement_upload_is_validated_and_category_scoped(): void
    {
        Storage::fake('local'); $source = $this->source('water');
        foreach (['accounting', 'finance', 'rso', 'it'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->postJson(route('water.recurrence', $source), $this->config())->assertForbidden();
        }
        $this->actingAs(User::find($source->created_by));
        $this->postJson(route('electricity.recurrence', $source), $this->config())->assertNotFound();
        $this->postJson(route('water.recurrence', $source), array_replace($this->config(), ['interval_months' => 2, 'lead_days' => 100]))->assertUnprocessable();
        $source->status = 'awaiting_soa'; $source->save();
        $this->postJson(route('water.statement', $source), ['amount' => '-1'])->assertUnprocessable();
        $this->postJson(route('electricity.statement', $source), [])->assertNotFound();
        $this->assertDatabaseCount('recurring_schedules', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
