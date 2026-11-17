<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RequestModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RequestRecordFiltersTest extends TestCase
{
    use RefreshDatabase;
    public static function modules(): array { return [['utak-pos'], ['internet-soa'], ['water'], ['electricity'], ['rental']]; }

    #[DataProvider('modules')]
    public function test_dates_amounts_and_presets_combine_with_status_search_and_sorting(string $module): void
    {
        $this->travelTo(now()->setDate(2026, 11, 17)->startOfDay());
        $this->actingAs($admin = User::factory()->create(['role' => 'admin']));
        foreach ([['2026-11-16', '100.00', 'awaiting_voucher'], ['2026-11-17', '200.00', 'vouchered'], ['2026-11-24', '300.00', 'awaiting_voucher'], ['2026-11-25', '400.00', 'awaiting_voucher'], ['2026-12-18', '500.00', 'awaiting_voucher'], ['2026-11-15', '600.00', 'paid'], ['2026-11-14', '700.00', 'cancelled']] as $index => [$date, $amount, $status]) {
            $record = RequestModule::query($module)->getModel()->newInstance();
            $record->store_name = 'BW Store '.$index; $record->amount = $amount; $record->status = $status;
            $record->{RequestModule::dateField($module)} = $date; $record->created_by = $admin->id;
            if ($module !== 'utak-pos') { $record->attachment_path = 'test.pdf'; $record->attachment_name = 'test.pdf'; }
            if (in_array($module, ['water', 'electricity', 'rental'])) $record->module = $module;
            $record->save();
        }
        $url = '/'.$module.'/records?status=all';
        $this->getJson($url.'&period=overdue')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.amount', '100.00');
        $this->getJson($url.'&period=next7')->assertJsonPath('total', 2);
        $this->getJson($url.'&period=next30')->assertJsonPath('total', 3);
        $this->getJson($url.'&period=this_month')->assertJsonPath('total', 6);
        $this->getJson($url.'&date_from=2026-11-17&date_to=2026-11-24&amount_min=200&amount_max=300&sort=amount&direction=desc')
            ->assertJsonPath('total', 2)->assertJsonPath('data.0.amount', '300.00');
        $this->getJson('/'.$module.'/records?status=awaiting_voucher&period=next30&search=Store%202&amount_min=250')
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.amount', '300.00');
        $this->getJson($url.'&amount_max=0')->assertJsonPath('total', 0);
    }

    public function test_invalid_ranges_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([
            'date_from=2026-11-20&date_to=2026-11-01' => 'date_to',
            'date_from=2026-02-30' => 'date_from',
            'amount_min=500&amount_max=100' => 'amount_max',
            'amount_min=-1' => 'amount_min',
            'amount_max=1.001' => 'amount_max',
            'period=invalid' => 'period',
        ] as $query => $field) $this->getJson('/utak-pos/records?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }
}
