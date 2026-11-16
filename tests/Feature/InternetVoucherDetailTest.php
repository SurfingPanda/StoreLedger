<?php

namespace Tests\Feature;

use App\Models\InternetSoa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternetVoucherDetailTest extends TestCase
{
    use RefreshDatabase;

    private function renewal(string $store = 'BW Makati'): InternetSoa
    {
        $record = new InternetSoa(['store_name' => $store, 'due_date' => '2026-12-01', 'amount' => '1500.00']);
        $record->attachment_path = 'internet-soas/statement.pdf';
        $record->attachment_name = 'statement.pdf';
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        $record->save();
        return $record;
    }

    public function test_opening_details_keeps_the_renewal_pending_and_shows_only_same_store_history(): void
    {
        $record = $this->renewal();
        $previous = $this->renewal();
        $previous->update(['status' => 'vouchered']);
        $this->renewal('BW Other store');
        $this->actingAs(User::factory()->create(['role' => 'accounting']));
        $this->withoutVite()->get(route('internet-soa.detail', $record))->assertOk()
            ->assertViewHas('appData', fn ($data) => $data['page'] === 'internet-voucher' && $data['renewalId'] === $record->id);
        $this->getJson(route('internet-soa.details', $record))->assertOk()->assertJsonPath('record.status', 'awaiting_voucher')
            ->assertJsonPath('record.amount', '1500.00')->assertJsonPath('record.creator.role', 'admin')
            ->assertJsonPath('history.total', 2)->assertJsonPath('comments.total', 0)->assertJsonMissingPath('record.creator.email');
        $this->assertDatabaseHas('internet_soas', ['id' => $record->id, 'status' => 'awaiting_voucher', 'voucher_action_at' => null]);
    }

    public function test_accounting_can_comment_and_complete_with_a_voucher_reference(): void
    {
        $record = $this->renewal();
        $other = $this->renewal();
        $accountant = User::factory()->create(['role' => 'accounting']);
        $this->actingAs($accountant)->postJson(route('internet-soa.comments', $record), ['body' => 'Checked the billing details.', 'user_id' => $other->created_by])
            ->assertCreated()->assertJsonPath('comment.author.name', $accountant->name);
        $this->getJson(route('internet-soa.details', $other))->assertJsonPath('comments.total', 0);
        $this->postJson(route('internet-soa.voucher', $record), ['action' => 'process', 'voucher_reference' => 'VCH-2026-001'])
            ->assertOk()->assertJsonPath('record.voucher_reference', 'VCH-2026-001');
        $this->getJson(route('internet-soa.details', $record))->assertOk()->assertJsonPath('record.voucher_actor.name', $accountant->name)
            ->assertJsonPath('record.status', 'vouchered')->assertJsonPath('comments.data.0.body', 'Checked the billing details.');
        $this->assertDatabaseHas('internet_soa_comments', ['internet_soa_id' => $record->id, 'user_id' => $accountant->id]);
    }

    public function test_comments_require_accounting_and_valid_text(): void
    {
        $record = $this->renewal();
        $this->get(route('internet-soa.detail', $record))->assertRedirect('/login');
        $this->getJson(route('internet-soa.details', $record))->assertUnauthorized();
        $this->postJson(route('internet-soa.comments', $record), ['body' => 'Note'])->assertUnauthorized();
        foreach (['admin', 'it', 'rso'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->postJson(route('internet-soa.comments', $record), ['body' => 'Note'])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'accounting']));
        foreach (['', '  ', str_repeat('x', 5001)] as $body) {
            $this->postJson(route('internet-soa.comments', $record), ['body' => $body])->assertUnprocessable();
        }
        $this->postJson(route('internet-soa.voucher', $record), ['action' => 'process', 'voucher_reference' => str_repeat('x', 101)])->assertUnprocessable();
        $this->assertDatabaseCount('internet_soa_comments', 0);
        $this->getJson('/internet-soa/records/999999/details')->assertNotFound();
        $this->withoutVite()->get('/internet-soa/records/999999')->assertNotFound();
    }

    public function test_history_and_comments_can_be_paginated(): void
    {
        $record = $this->renewal();
        for ($i = 0; $i < 11; $i++) $this->renewal();
        $this->actingAs($accountant = User::factory()->create(['role' => 'accounting']));
        for ($i = 0; $i < 21; $i++) {
            $comment = $record->comments()->make(['body' => 'Note '.$i]);
            $comment->user_id = $accountant->id;
            $comment->save();
        }
        $this->getJson(route('internet-soa.details', $record).'?history_page=2&comments_page=2')->assertOk()
            ->assertJsonCount(2, 'history.data')->assertJsonCount(1, 'comments.data')->assertJsonPath('comments.data.0.body', 'Note 0');
    }
}
