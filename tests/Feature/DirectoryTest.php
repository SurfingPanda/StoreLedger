<?php
namespace Tests\Feature;

use App\Models\User;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\StoreBillingAccount;
use App\Services\DirectoryLinks;
use App\Services\RequestModule;
use App\Services\RecurringBilling;
use App\Models\RecurringSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DirectoryTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Storage::fake('local'); }
    public static function modules(): array { return array_map(fn ($module) => [$module], array_keys(RequestModule::LABELS)); }
    private function account(string $module = 'water', string $code = 'BW-MKT', string $number = '001-234'): StoreBillingAccount
    {
        $store = new Store(['code' => $code, 'name' => 'BW Makati']); $store->name_key = DirectoryLinks::key($store->name); $store->save();
        $supplier = Supplier::first();
        if (!$supplier) { $supplier = new Supplier(['name' => 'Provider Inc']); $supplier->name_key = DirectoryLinks::key($supplier->name); $supplier->save(); }
        $account = new StoreBillingAccount(['store_id' => $store->id, 'supplier_id' => $supplier->id, 'module' => $module, 'account_number' => $number, 'billing_cycle_months' => 3]);
        $account->identity_key = hash('sha256', $store->id.'|'.$supplier->id.'|'.$module.'|'.strtolower($number)); $account->save();
        return $account;
    }
    private function bill(string $module, int $account, string $month = '2026-11')
    {
        $payload = ['billing_account_id' => $account, 'billing_period' => $month, 'amount' => '1200', RequestModule::dateField($module) => '2026-12-15'];
        if ($module !== 'utak-pos') $payload['soa'] = UploadedFile::fake()->create('bill.pdf', 2, 'application/pdf');
        return $this->post('/'.$module.'/records', $payload, ['Accept' => 'application/json']);
    }

    public function test_admin_manages_directory_and_other_roles_can_only_view(): void
    {
        $this->getJson('/directory/records')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'finance']))->getJson('/directory/records')->assertOk();
        $this->postJson('/directory/stores', [])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $rso = User::factory()->create(['role' => 'rso']);
        $this->postJson('/directory/stores', ['code' => 'BW-001', 'name' => 'BW Store', 'assigned_rso_id' => $rso->id, 'is_active' => true])->assertCreated();
        $store = Store::firstOrFail();
        $this->postJson('/directory/stores', ['code' => 'bw-001', 'name' => 'Duplicate', 'is_active' => true])->assertUnprocessable();
        $wrongRole = User::factory()->create(['role' => 'finance']);
        $this->postJson('/directory/stores/'.$store->id, ['code' => 'BW-001', 'name' => 'Renamed', 'assigned_rso_id' => $wrongRole->id, 'is_active' => true])->assertUnprocessable();
        $this->postJson('/directory/suppliers', ['name' => 'Provider', 'email' => 'billing@example.com', 'phone' => '1234', 'is_active' => true])->assertCreated();
        $this->postJson('/directory/suppliers', ['name' => ' PROVIDER ', 'is_active' => true])->assertUnprocessable();
        $supplier = Supplier::firstOrFail();
        $payload = ['store_id' => $store->id, 'supplier_id' => $supplier->id, 'module' => 'water', 'account_number' => '00123', 'billing_cycle_months' => 1, 'is_active' => true];
        $this->postJson('/directory/accounts', $payload)->assertCreated();
        $this->postJson('/directory/accounts', $payload)->assertUnprocessable();
        $this->getJson('/directory/records')->assertJsonPath('stores.0.assigned_rso.name', $rso->name)->assertJsonPath('accounts.0.account_number', '00123');
        $this->withoutVite()->get('/directory')->assertOk()->assertViewHas('appData', fn ($data) => $data['page'] === 'directory');
    }

    public function test_billing_account_amount_is_saved_only_for_internet(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $account = $this->account('internet-soa');
        $url = '/directory/accounts/'.$account->id;
        $payload = $account->only(['store_id', 'supplier_id', 'module', 'account_number', 'billing_cycle_months', 'is_active']);

        $this->postJson($url, $payload + ['amount' => '1299.999'])->assertJsonValidationErrors('amount');
        $this->postJson($url, $payload + ['amount' => '1299.50'])->assertOk();
        $this->getJson('/directory/records')->assertJsonPath('accounts.0.amount', '1299.50');

        $payload['module'] = 'water';
        $this->postJson($url, $payload + ['amount' => '1299.50'])->assertOk();
        $this->getJson('/directory/records')->assertJsonPath('accounts.0.amount', null);
    }

    public function test_store_ownership_is_saved_updated_validated_and_returned(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $payload = ['code' => 'BW-OWN', 'name' => 'Ownership store', 'is_active' => true, 'ownership_type' => 'corporate_owned'];
        $this->postJson('/directory/stores', $payload)->assertCreated()->assertJsonPath('record.ownership_type', 'corporate_owned');
        $store = Store::firstOrFail();
        $url = '/directory/stores/'.$store->id;
        $this->postJson($url, array_replace($payload, ['ownership_type' => 'franchise']))
            ->assertOk()->assertJsonPath('record.ownership_type', 'franchise');
        $this->assertDatabaseHas('stores', ['id' => $store->id, 'ownership_type' => 'franchise']);
        $this->getJson('/directory/records')->assertOk()->assertJsonPath('stores.0.ownership_type', 'franchise');
        foreach (['invalid', '', null] as $ownership) {
            $this->postJson($url, array_replace($payload, ['ownership_type' => $ownership]))
                ->assertUnprocessable()->assertJsonValidationErrors('ownership_type');
        }
        unset($payload['ownership_type']);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('record.ownership_type', 'franchise');
        $this->assertSame('franchise', $store->fresh()->ownership_type);
    }

    public function test_ownership_migration_leaves_existing_stores_unclassified(): void
    {
        $migration = require database_path('migrations/2026_11_18_000001_add_ownership_type_to_stores.php');
        $migration->down();
        $store = $this->account()->store;
        $migration->up();
        $this->assertNull($store->fresh()->ownership_type);
        $this->assertSame('BW Makati', $store->fresh()->name);
    }

    public function test_store_codes_have_independent_ownership_sequences_and_ignore_client_codes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([['corporate_owned', 'BW0001'], ['franchise', 'FR0001'], ['corporate_owned', 'BW0002'], ['franchise', 'FR0002']] as [$ownership, $code]) {
            $this->postJson('/directory/stores', ['name' => 'Store '.$code, 'ownership_type' => $ownership, 'is_active' => true, 'code' => 'CUSTOM'])
                ->assertCreated()->assertJsonPath('record.code', $code);
        }
        $this->postJson('/directory/stores', ['name' => 'Without manual code', 'ownership_type' => 'corporate_owned', 'is_active' => true])
            ->assertCreated()->assertJsonPath('record.code', 'BW0003');
        $this->postJson('/directory/stores', ['name' => 'Missing ownership', 'is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('ownership_type');
    }

    public function test_directory_previews_actual_next_codes_without_consuming_them(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->account('water', 'BW0001');
        for ($i = 0; $i < 2; $i++) {
            $this->getJson('/directory/records')->assertOk()
                ->assertJsonPath('next_store_codes.corporate_owned', 'BW0002')
                ->assertJsonPath('next_store_codes.franchise', 'FR0001');
        }
        $this->postJson('/directory/stores', ['name' => 'New store', 'ownership_type' => 'corporate_owned', 'is_active' => true])
            ->assertCreated()->assertJsonPath('record.code', 'BW0002');
        $this->getJson('/directory/records')->assertOk()
            ->assertJsonPath('next_store_codes.corporate_owned', 'BW0003')
            ->assertJsonPath('next_store_codes.franchise', 'FR0001');
    }

    public function test_codes_skip_existing_ids_and_remain_stable_until_ownership_changes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $existing = $this->account('water', 'BW0001')->store;
        $payload = ['name' => 'New corporate store', 'ownership_type' => 'corporate_owned', 'is_active' => true];
        $created = $this->postJson('/directory/stores', $payload)->assertCreated()->assertJsonPath('record.code', 'BW0002')->json('record');
        $url = '/directory/stores/'.$created['id'];
        $this->postJson($url, array_replace($payload, ['name' => 'Renamed', 'code' => 'MANUAL']))
            ->assertOk()->assertJsonPath('record.code', 'BW0002');
        $this->postJson($url, array_replace($payload, ['ownership_type' => 'franchise']))
            ->assertOk()->assertJsonPath('record.code', 'FR0001')->assertJsonPath('record.id', $created['id']);
        $this->postJson('/directory/stores', $payload)->assertCreated()->assertJsonPath('record.code', 'BW0003');
        $this->assertSame('BW0001', $existing->fresh()->code);
    }

    public function test_store_code_migration_starts_after_existing_numbers(): void
    {
        $migration = require database_path('migrations/2026_11_18_000002_create_store_code_sequences.php');
        $migration->down();
        $this->account('water', 'BW0027');
        $this->account('water', 'FR0009');
        $migration->up();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([['corporate_owned', 'BW0028'], ['franchise', 'FR0010']] as [$ownership, $code]) {
            $this->postJson('/directory/stores', ['name' => $code, 'ownership_type' => $ownership, 'is_active' => true])
                ->assertCreated()->assertJsonPath('record.code', $code);
        }
    }

    #[DataProvider('modules')]
    public function test_history_and_duplicates_follow_store_ids_after_renames_and_exclude_other_same_named_stores(string $module): void
    {
        $account = $this->account($module);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->bill($module, $account->id)->assertCreated();
        $first = RequestModule::query($module)->firstOrFail();
        $this->assertSame('001-234', $first->account_number);
        $this->postJson('/directory/stores/'.$account->store_id, ['code' => 'BW-MKT', 'name' => 'BW New Makati', 'is_active' => true])->assertOk();
        $this->bill($module, $account->id, '2026-12')->assertCreated();
        $other = $this->account($module, 'BW-MKT-OTHER');
        $this->bill($module, $other->id)->assertCreated();
        $this->getJson(route($module.'.details', $first))->assertJsonPath('history.total', 2)->assertJsonPath('record.store.code', 'BW-MKT')
            ->assertJsonPath('record.store.name', 'BW New Makati')->assertJsonPath('record.store_name', 'BW Makati');
        $this->bill($module, $account->id)->assertUnprocessable()->assertJsonValidationErrors('duplicate_bill');
    }

    #[DataProvider('modules')]
    public function test_wrong_module_and_inactive_accounts_are_rejected_and_account_numbers_separate_bills(string $module): void
    {
        $account = $this->account($module);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->bill($module, $account->id)->assertCreated();
        $second = new StoreBillingAccount(['store_id' => $account->store_id, 'supplier_id' => $account->supplier_id, 'module' => $module, 'account_number' => '999', 'billing_cycle_months' => 1]);
        $second->identity_key = hash('sha256', 'second'); $second->save();
        $this->bill($module, $second->id)->assertCreated();
        $account->is_active = false; $account->save();
        $this->bill($module, $account->id, '2026-10')->assertUnprocessable();
        $second->module = $module === 'water' ? 'rental' : 'water'; $second->save();
        $this->bill($module, $second->id, '2026-10')->assertUnprocessable();
        $this->assertSame(2, RequestModule::query($module)->count());
    }

    public function test_used_account_cannot_move_and_new_numbers_do_not_rewrite_bill_snapshots(): void
    {
        $account = $this->account();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->bill('water', $account->id)->assertCreated();
        $payload = $account->only('store_id', 'supplier_id', 'module', 'account_number', 'billing_cycle_months', 'is_active');
        $this->postJson('/directory/accounts/'.$account->id, array_replace($payload, ['module' => 'rental']))->assertUnprocessable();
        $this->postJson('/directory/accounts/'.$account->id, array_replace($payload, ['account_number' => 'NEW-456']))->assertOk();
        $this->assertSame('001-234', RequestModule::query('water')->first()->account_number);
        $this->bill('water', $account->id)->assertUnprocessable();
    }

    public function test_recurring_bills_keep_directory_links_and_inactive_accounts_pause_generation(): void
    {
        $account = $this->account();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->bill('water', $account->id)->assertCreated();
        $record = RequestModule::query('water')->firstOrFail();
        $this->postJson(route('water.recurrence', $record), ['active' => true, 'interval_months' => 3, 'lead_days' => 15, 'amount' => '1200'])->assertOk();
        $schedule = RecurringSchedule::firstOrFail();
        $this->travelTo(\Carbon\Carbon::parse('2027-03-01'));
        $account->is_active = false; $account->save();
        $this->assertSame(0, RecurringBilling::generate($schedule->id));
        $this->assertSame('2027-03-15', $schedule->fresh()->next_due_date->toDateString());
        $account->is_active = true; $account->save();
        $this->assertSame(1, RecurringBilling::generate($schedule->id));
        $generated = RequestModule::query('water')->whereNotNull('recurring_schedule_id')->firstOrFail();
        $this->assertSame($account->store_id, $generated->store_id);
        $this->assertSame($account->id, $generated->billing_account_id);
        $this->travelBack();
    }

    public function test_migration_backfills_existing_bills_without_changing_snapshots(): void
    {
        $migration = require database_path('migrations/2026_11_17_000005_create_store_supplier_directory.php');
        $migration->down();
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['pos_renewals', 'internet_soas', 'billing_soas'] as $table) {
            $data = ['store_name' => ' BW   Makati ', 'provider' => 'Provider Inc', 'amount' => 1500, 'created_by' => $admin->id, 'status' => 'awaiting_voucher', 'created_at' => now(), 'updated_at' => now()];
            $data[$table === 'pos_renewals' ? 'renewal_date' : 'due_date'] = '2026-12-15';
            if ($table === 'billing_soas') $data['module'] = 'water';
            DB::table($table)->insert($data);
        }
        $migration->up();
        $this->assertDatabaseCount('stores', 1); $this->assertDatabaseCount('suppliers', 1); $this->assertDatabaseCount('store_billing_accounts', 3);
        foreach (['pos_renewals', 'internet_soas', 'billing_soas'] as $table) {
            $row = DB::table($table)->first(); $this->assertNotNull($row->store_id); $this->assertNotNull($row->billing_account_id);
            $this->assertSame(' BW   Makati ', $row->store_name);
        }
    }
}
