<?php

namespace Tests\Feature;

use App\Models\BillingSoa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BillingSoaTest extends TestCase
{
    public static function modules(): array
    {
        return [['electricity'], ['rental'], ['water']];
    }

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function payload(): array
    {
        return ['store_name' => 'BW Makati', 'amount' => '1899.50', 'due_date' => '2026-12-15',
            'soa' => UploadedFile::fake()->create('internet-soa.pdf', 20, 'application/pdf')];
    }

    #[DataProvider('modules')]
    public function test_admin_can_save_list_and_download_an_soa(string $module): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/'.$module.'/records', $this->payload(), ['Accept' => 'application/json'])->assertCreated();
        $record = BillingSoa::firstOrFail();
        $this->assertSame($module, $record->module);
        $other = $module === 'water' ? 'rental' : 'water';
        $this->getJson('/'.$other.'/records')->assertJsonPath('total', 0);
        $this->get('/'.$other.'/'.$record->id.'/attachment')->assertNotFound();
        $this->assertDatabaseHas('billing_soas', ['store_name' => 'BW Makati', 'amount' => '1899.50', 'due_date' => '2026-12-15', 'created_by' => $admin->id]);
        Storage::disk('local')->assertExists($record->attachment_path);
        $this->getJson('/'.$module.'/records')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.attachment_name', 'internet-soa.pdf')
            ->assertJsonMissingPath('data.0.attachment_path');
        $this->get(route($module.'.attachment', $record))->assertOk()->assertDownload('internet-soa.pdf');
        $this->withoutVite()->get('/'.$module)->assertOk()->assertViewHas('appData', fn ($data) => $data['page'] === $module);
        $this->actingAs(User::factory()->create(['role' => 'accounting']))->getJson('/'.$module.'/records')->assertOk();
        $this->get(route($module.'.attachment', $record))->assertOk();
        Storage::disk('local')->delete($record->attachment_path);
        $this->get(route($module.'.attachment', $record))->assertNotFound();
    }

    #[DataProvider('modules')]
    public function test_guests_are_blocked_and_only_admins_can_create_records(string $module): void
    {
        $this->get('/'.$module)->assertRedirect('/login');
        $this->getJson('/'.$module.'/records')->assertUnauthorized();
        $this->getJson('/'.$module.'/1/attachment')->assertUnauthorized();
        $this->postJson('/'.$module.'/records', [])->assertUnauthorized();
        foreach (['accounting', 'finance', 'rso', 'it'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post('/'.$module.'/records', $this->payload(), ['Accept' => 'application/json'])->assertForbidden();
        }
        $this->assertDatabaseCount('billing_soas', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[DataProvider('modules')]
    public function test_invalid_billing_details_and_attachments_are_rejected(string $module): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/'.$module.'/records', [])->assertUnprocessable()->assertJsonValidationErrors(['store_name', 'amount', 'due_date', 'soa']);
        foreach (['0', '-1', '1.234', '10000000000'] as $amount) {
            $this->post('/'.$module.'/records', array_replace($this->payload(), ['amount' => $amount]), ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        foreach ([UploadedFile::fake()->create('file.txt', 1, 'text/plain'), UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')] as $file) {
            $this->post('/'.$module.'/records', array_replace($this->payload(), ['soa' => $file]), ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('soa');
        }
        $this->post('/'.$module.'/records', array_replace($this->payload(), ['store_name' => ' ', 'due_date' => '2026-02-30']), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['store_name', 'due_date']);
        $this->assertDatabaseCount('billing_soas', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[DataProvider('modules')]
    public function test_records_are_searchable_and_paginated_by_due_date(string $module): void
    {
        $this->actingAs($admin = User::factory()->create(['role' => 'admin']));
        foreach (range(17, 1) as $day) {
            $record = new BillingSoa(['store_name' => 'Store '.$day, 'amount' => '1500.00', 'due_date' => sprintf('2026-12-%02d', $day)]);
            $record->module = $module;
            $record->attachment_path = 'billing-soas/example.pdf';
            $record->attachment_name = 'example.pdf';
            $record->created_by = $admin->id;
            $record->save();
        }
        $this->getJson('/'.$module.'/records')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('data.0.store_name', 'Store 1');
        $this->getJson('/'.$module.'/records?page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/'.$module.'/records?search=Store%2017')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.store_name', 'Store 17');
    }
}
