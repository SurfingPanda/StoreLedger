<?php

namespace Tests\Feature;

use App\Models\PosRenewal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosVoucherDetailTest extends TestCase
{
    use RefreshDatabase;

    private function renewal(string $store = 'BW Makati'): PosRenewal
    {
        $record = new PosRenewal(['store_name' => $store, 'renewal_date' => '2026-12-01', 'amount' => '1500.00']);
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
        $this->withoutVite()->get(route('utak-pos.detail', $record))->assertOk()
            ->assertViewHas('appData', fn ($data) => $data['page'] === 'pos-voucher' && $data['renewalId'] === $record->id);
        $this->getJson(route('utak-pos.details', $record))->assertOk()->assertJsonPath('record.status', 'awaiting_voucher')
            ->assertJsonPath('record.amount', '1500.00')->assertJsonPath('record.creator.role', 'admin')
            ->assertJsonPath('history.total', 2)->assertJsonPath('comments.total', 0)->assertJsonMissingPath('record.creator.email');
        $this->assertDatabaseHas('pos_renewals', ['id' => $record->id, 'status' => 'awaiting_voucher', 'voucher_action_at' => null]);
    }

    public function test_accounting_can_comment_and_complete_with_a_voucher_reference(): void
    {
        $record = $this->renewal();
        $other = $this->renewal();
        $other->update(['billing_period' => '2026-11']);
        $accountant = User::factory()->create(['role' => 'accounting']);
        $this->actingAs($accountant)->postJson(route('utak-pos.comments', $record), ['body' => 'Checked the billing details.', 'user_id' => $other->created_by])
            ->assertCreated()->assertJsonPath('comment.author.name', $accountant->name);
        $this->getJson(route('utak-pos.details', $other))->assertJsonPath('comments.total', 0);
        $this->postJson(route('utak-pos.voucher', $record), ['action' => 'process', 'voucher_reference' => 'VCH-2026-001'])
            ->assertOk()->assertJsonPath('record.voucher_reference', 'VCH-2026-001');
        $this->getJson(route('utak-pos.details', $record))->assertOk()->assertJsonPath('record.voucher_actor.name', $accountant->name)
            ->assertJsonPath('record.status', 'vouchered')->assertJsonPath('comments.data.0.body', 'Checked the billing details.');
        $this->assertDatabaseHas('pos_renewal_comments', ['pos_renewal_id' => $record->id, 'user_id' => $accountant->id]);
    }

    public function test_comments_require_accounting_and_valid_text(): void
    {
        $record = $this->renewal();
        $this->get(route('utak-pos.detail', $record))->assertRedirect('/login');
        $this->getJson(route('utak-pos.details', $record))->assertUnauthorized();
        $this->postJson(route('utak-pos.comments', $record), ['body' => 'Note'])->assertUnauthorized();
        foreach (['admin', 'it', 'rso'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->postJson(route('utak-pos.comments', $record), ['body' => 'Note'])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'accounting']));
        foreach (['', '  ', str_repeat('x', 5001)] as $body) {
            $this->postJson(route('utak-pos.comments', $record), ['body' => $body])->assertUnprocessable();
        }
        $this->postJson(route('utak-pos.voucher', $record), ['action' => 'process', 'voucher_reference' => str_repeat('x', 101)])->assertUnprocessable();
        $this->assertDatabaseCount('pos_renewal_comments', 0);
        $this->getJson('/utak-pos/records/999999/details')->assertNotFound();
        $this->withoutVite()->get('/utak-pos/records/999999')->assertNotFound();
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
        $this->getJson(route('utak-pos.details', $record).'?history_page=2&comments_page=2')->assertOk()
            ->assertJsonCount(2, 'history.data')->assertJsonCount(1, 'comments.data')->assertJsonPath('comments.data.0.body', 'Note 0');
    }
}
