<?php

namespace Tests\Feature;

use App\Models\InternetSoa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InternetSoaTest extends TestCase
{
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

    public function test_admin_can_save_list_and_download_an_soa(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/internet-soa/records', $this->payload(), ['Accept' => 'application/json'])->assertCreated();
        $record = InternetSoa::firstOrFail();
        $this->assertDatabaseHas('internet_soas', ['store_name' => 'BW Makati', 'amount' => '1899.50', 'due_date' => '2026-12-15', 'created_by' => $admin->id]);
        Storage::disk('local')->assertExists($record->attachment_path);
        $this->getJson('/internet-soa/records')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.attachment_name', 'internet-soa.pdf')
            ->assertJsonMissingPath('data.0.attachment_path');
        $this->get(route('internet-soa.attachment', $record))->assertOk()->assertDownload('internet-soa.pdf');
        $this->withoutVite()->get('/internet-soa')->assertOk()->assertViewHas('appData', fn ($data) => $data['page'] === 'internet-soa');
        $this->actingAs(User::factory()->create(['role' => 'accounting']))->getJson('/internet-soa/records')->assertOk();
        $this->get(route('internet-soa.attachment', $record))->assertOk();
        Storage::disk('local')->delete($record->attachment_path);
        $this->get(route('internet-soa.attachment', $record))->assertNotFound();
    }

    public function test_guests_are_blocked_and_only_admins_can_create_records(): void
    {
        $this->get('/internet-soa')->assertRedirect('/login');
        $this->getJson('/internet-soa/records')->assertUnauthorized();
        $this->getJson('/internet-soa/1/attachment')->assertUnauthorized();
        $this->postJson('/internet-soa/records', [])->assertUnauthorized();
        foreach (['accounting', 'finance', 'rso', 'it'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post('/internet-soa/records', $this->payload(), ['Accept' => 'application/json'])->assertForbidden();
        }
        $this->assertDatabaseCount('internet_soas', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_purchase_request_is_uploaded_and_available_from_the_internet_soa_details(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/internet-soa/records', $this->payload() + [
            'purchase_request' => UploadedFile::fake()->create('invalid.txt', 1, 'text/plain'),
        ])->assertUnprocessable()->assertJsonValidationErrors('purchase_request');
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->postJson('/internet-soa/records', $this->payload() + [
            'purchase_request' => UploadedFile::fake()->create('purchase-request.pdf', 20, 'application/pdf'),
        ])->assertCreated();
        $record = InternetSoa::firstOrFail();
        Storage::disk('local')->assertExists($record->purchase_request_path);
        $url = route('internet-soa.purchase-request', $record);

        $this->actingAs(User::factory()->create(['role' => 'accounting']))
            ->getJson(route('internet-soa.details', $record))->assertOk()
            ->assertJsonPath('record.purchase_request_name', 'purchase-request.pdf')
            ->assertJsonPath('record.purchase_request_url', $url)
            ->assertJsonMissingPath('record.purchase_request_path');
        $this->get($url)->assertOk()->assertDownload('purchase-request.pdf');
    }

    public function test_invalid_billing_details_and_attachments_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/internet-soa/records', [])->assertUnprocessable()->assertJsonValidationErrors(['store_name', 'amount', 'due_date', 'soa']);
        foreach (['0', '-1', '1.234', '10000000000'] as $amount) {
            $this->post('/internet-soa/records', array_replace($this->payload(), ['amount' => $amount]), ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        foreach ([UploadedFile::fake()->create('file.txt', 1, 'text/plain'), UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')] as $file) {
            $this->post('/internet-soa/records', array_replace($this->payload(), ['soa' => $file]), ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('soa');
        }
        $this->post('/internet-soa/records', array_replace($this->payload(), ['store_name' => ' ', 'due_date' => '2026-02-30']), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['store_name', 'due_date']);
        $this->assertDatabaseCount('internet_soas', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_records_are_searchable_and_paginated_by_due_date(): void
    {
        $this->actingAs($admin = User::factory()->create(['role' => 'admin']));
        foreach (range(17, 1) as $day) {
            $record = new InternetSoa(['store_name' => 'Store '.$day, 'amount' => '1500.00', 'due_date' => sprintf('2026-12-%02d', $day)]);
            $record->attachment_path = 'internet-soas/example.pdf';
            $record->attachment_name = 'example.pdf';
            $record->created_by = $admin->id;
            $record->save();
        }
        $this->getJson('/internet-soa/records')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('data.0.store_name', 'Store 1');
        $this->getJson('/internet-soa/records?page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/internet-soa/records?search=Store%2017')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.store_name', 'Store 17');
    }
}
