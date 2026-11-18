<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RequestModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TableSortingTest extends TestCase
{
    use RefreshDatabase;

    public static function modules(): array
    {
        return [['utak-pos'], ['internet-soa'], ['water'], ['electricity'], ['rental']];
    }

    #[DataProvider('modules')]
    public function test_records_and_history_sort_numerically_before_pagination(string $module): void
    {
        $this->actingAs($admin = User::factory()->create(['role' => 'admin']));
        for ($number = 1; $number <= 12; $number++) {
            $record = RequestModule::query($module)->getModel()->newInstance();
            $record->store_name = 'Same store'; $record->amount = $number;
            $record->created_by = $admin->id; $record->status = 'awaiting_voucher';
            $record->{RequestModule::dateField($module)} = '2026-12-01';
            if ($module !== 'utak-pos') { $record->attachment_name = 'bill.pdf'; $record->attachment_path = 'bill.pdf'; }
            if (in_array($module, ['water', 'electricity', 'rental'])) $record->module = $module;
            $record->save();
            if ($number >= 11) {
                $payment = $record->payment()->make(['paid_on' => '2026-12-'.$number, 'method' => 'cash', 'reference' => 'PAY-'.$number]);
                $payment->amount = $number; $payment->user_id = $admin->id;
                $payment->proof_name = 'proof.pdf'; $payment->proof_path = 'proof.pdf'; $payment->save();
            }
        }
        $url = route($module.'.details', $record);
        $this->getJson($url.'?history_sort=amount&history_direction=asc&history_page=2')->assertOk()
            ->assertJsonPath('history.total', 12)->assertJsonPath('history.data.0.amount', '11.00');
        $this->getJson($url.'?history_sort=amount&history_direction=desc')->assertOk()
            ->assertJsonPath('history.data.0.amount', '12.00')->assertJsonPath('history.data.9.amount', '3.00');
        $this->getJson($url.'?history_sort=paid_on&history_direction=desc')->assertOk()
            ->assertJsonPath('history.data.0.payment.paid_on', '2026-12-12');
        foreach (['id', 'date', 'voucher_action_at', 'status'] as $sort) {
            $this->getJson($url.'?history_sort='.$sort.'&history_direction=asc')->assertOk();
        }
        $this->getJson($url.'?history_sort=unsafe')->assertUnprocessable()->assertJsonValidationErrors('history_sort');
        $this->getJson('/'.$module.'/records?sort=amount&direction=desc&per_page=5&page=2')->assertOk()
            ->assertJsonPath('data.0.amount', '7.00')->assertJsonPath('data.4.amount', '3.00');
        $this->getJson('/dashboard/records?sort=amount&direction=asc&per_page=5&page=2')->assertOk()
            ->assertJsonPath('records.data.0.amount', fn ($amount) => (float) $amount === 6.0)
            ->assertJsonPath('records.data.4.amount', fn ($amount) => (float) $amount === 10.0);
    }

    public function test_users_sort_the_complete_filtered_result_and_reject_unknown_columns(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        for ($i = 1; $i <= 12; $i++) User::factory()->create(['name' => 'Sorted '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'role' => 'it']);
        $base = '/users/records?role=it&per_page=5&sort=name';
        $this->getJson($base.'&direction=asc&page=2')->assertOk()->assertJsonPath('users.data.0.name', 'Sorted 06');
        $this->getJson($base.'&direction=desc')->assertOk()->assertJsonPath('users.data.0.name', 'Sorted 12');
        $this->getJson('/users/records?sort=password')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/dashboard/records?direction=invalid')->assertUnprocessable()->assertJsonValidationErrors('direction');
        $this->getJson('/security-audit/records?sort=invalid')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_automation_sorts_numeric_attempts_and_keeps_sort_parameters_independent(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([2, 10, 1] as $attempts) {
            DB::table('workflow_mail_deliveries')->insert([
                'module' => 'water', 'request_id' => $attempts, 'event' => 'finance_payment',
                'email' => 'sort'.$attempts.'@example.com', 'review_cycle' => 1, 'attempts' => $attempts,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $url = '/automation-health/records?email_status=all&emails_sort=attempts&per_page=1';
        $this->getJson($url.'&emails_direction=asc&emails_page=2')->assertOk()->assertJsonPath('emails.data.0.attempts', 2);
        $this->getJson($url.'&emails_direction=desc')->assertOk()->assertJsonPath('emails.data.0.attempts', 10);
        foreach (['state', 'delivery_at', 'email', 'request'] as $sort) {
            $this->getJson('/automation-health/records?emails_sort='.$sort.'&issues_sort=resolved_at&runs_sort=duration_ms')->assertOk();
        }
        $this->getJson('/automation-health/records?runs_sort=invalid')->assertUnprocessable()->assertJsonValidationErrors('runs_sort');
    }
}
