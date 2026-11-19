<?php

namespace Tests\Feature;

use App\Models\InboxNotification;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\RequestModule;
use App\Services\WorkflowNotifications;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    private function record(string $module, string $status = 'awaiting_voucher', int $offset = 1, ?string $target = null)
    {
        $record = RequestModule::query($module)->getModel()->newInstance();
        $record->forceFill(['store_name' => 'Inbox test store', 'amount' => '100.00', 'status' => $status,
            'created_by' => User::factory()->create(['role' => 'admin'])->id, 'revision_target' => $target,
            RequestModule::dateField($module) => now('Asia/Manila')->addDays($offset)->toDateString()]);
        if (in_array($module, ['water', 'electricity', 'rental'])) $record->module = $module;
        $record->save(); return $record;
    }

    public static function teams(): array
    {
        return [['accounting', 'awaiting_voucher', null], ['accounting', 'needs_revision', 'accounting'],
            ['finance', 'vouchered', null], ['admin', 'needs_revision', 'requester'], ['rso', 'awaiting_soa', null],
            ['rso', 'needs_revision', 'requester']];
    }

    #[DataProvider('teams')]
    public function test_action_and_overdue_alerts_are_personal_and_independent_of_email(string $role, string $status, ?string $target): void
    {
        config(['mail.default' => 'log']);
        foreach (array_keys(NotificationSetting::EVENTS) as $event) NotificationSetting::create(['event' => $event, 'mode' => 'off', 'emails' => []]);
        $record = $this->record('water', $status, -1, $target);
        $this->record('water', 'paid', -1);
        $this->record('water', 'cancelled', -1);
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->getJson('/notifications')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('unread_count', 1)->assertJsonPath('notifications.total', 1)
            ->assertJsonPath('notifications.data.0.kind', 'overdue')
            ->assertJsonPath('notifications.data.0.url', route('water.detail', $record))
            ->assertJsonMissingPath('notifications.data.0.user_id');
        $this->getJson('/notifications')->assertJsonPath('unread_count', 1)->assertJsonPath('notifications.total', 1);
        $this->assertDatabaseCount('inbox_notifications', 1);
        $other = User::factory()->create(['role' => 'it']);
        $this->actingAs($other)->getJson('/notifications')->assertJsonPath('unread_count', 0)->assertJsonPath('notifications.total', 0);
    }

    public function test_rso_handles_shared_modules_and_never_receives_other_teams_action_alerts(): void
    {
        foreach (array_keys(RequestModule::LABELS) as $module) {
            $this->record($module, 'needs_revision', -1, 'requester');
            $this->record($module, 'needs_revision', -1, 'accounting');
            $this->record($module, 'awaiting_voucher', -1);
            $this->record($module, 'vouchered', -1);
        }
        $this->actingAs(User::factory()->create(['role' => 'rso']))->getJson('/notifications')->assertOk()
            ->assertJsonPath('unread_count', 3)->assertJsonPath('notifications.total', 3);
        $this->actingAs(User::factory()->create(['role' => 'finance']))->getJson('/notifications')->assertOk()
            ->assertJsonPath('unread_count', 5)->assertJsonPath('notifications.total', 5);
        $this->actingAs(User::factory()->create(['role' => 'accounting']))->getJson('/notifications')->assertOk()
            ->assertJsonPath('unread_count', 10)->assertJsonPath('notifications.total', 10);
    }

    public function test_manila_midnight_creates_one_overdue_alert_and_preserves_read_state(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 15:59:00', 'UTC'));
        $record = $this->record('internet-soa', 'awaiting_voucher', 0);
        $user = User::factory()->create(['role' => 'accounting']);
        $first = $this->actingAs($user)->getJson('/notifications')->assertJsonPath('notifications.data.0.kind', 'action')->json('notifications.data.0.id');
        $this->postJson('/notifications/'.$first.'/read')->assertOk();
        $this->getJson('/notifications?filter=unread')->assertJsonPath('unread_count', 0)->assertJsonPath('notifications.total', 0);
        $this->travel(2)->minutes();
        $second = $this->getJson('/notifications')->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.data.0.kind', 'overdue')->json('notifications.data.0.id');
        $this->assertNotSame($first, $second);
        $this->assertNotNull(InboxNotification::find($first)->resolved_at);
        $this->postJson('/notifications/'.$second.'/read')->assertOk();
        $this->travel(1)->days();
        $this->getJson('/notifications')->assertJsonPath('unread_count', 0)->assertJsonPath('notifications.total', 2);
        $record->status = 'vouchered'; $record->workflow_version++; $record->save();
        $this->getJson('/notifications')->assertJsonPath('unread_count', 0);
        $this->assertNotNull(InboxNotification::find($second)->resolved_at);
        $this->travelBack();
    }

    public function test_progress_updates_reach_active_rso_accounts_even_when_email_is_disabled(): void
    {
        NotificationSetting::create(['event' => 'rso_voucher_processed', 'mode' => 'off', 'emails' => []]);
        NotificationSetting::create(['event' => 'rso_payment_processed', 'mode' => 'off', 'emails' => []]);
        $rso = User::factory()->create(['role' => 'rso']);
        $disabled = User::factory()->create(['role' => 'rso', 'is_active' => false]);
        $record = $this->record('utak-pos');
        $this->actingAs(User::factory()->create(['role' => 'accounting']))
            ->postJson(route('utak-pos.voucher', $record), ['action' => 'process', 'voucher_reference' => 'INBOX-1'])->assertOk();
        $record->refresh();
        WorkflowNotifications::voucherProcessed($record);
        $this->actingAs($rso)->getJson('/notifications')->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.data.0.title', 'Voucher processed');
        $record->status = 'paid'; $record->workflow_version++; $record->save();
        WorkflowNotifications::paymentProcessed($record);
        $this->getJson('/notifications')->assertJsonPath('unread_count', 2)
            ->assertJsonPath('notifications.data.0.title', 'Payment recorded');
        $this->assertSame(0, InboxNotification::where('user_id', $disabled->id)->count());
        $rso->role = 'finance'; $rso->save();
        $this->getJson('/notifications')->assertJsonPath('notifications.total', 0);
    }

    public function test_read_controls_are_scoped_to_the_owner_and_preserve_new_arrivals(): void
    {
        $this->record('water');
        $first = User::factory()->create(['role' => 'accounting']);
        $second = User::factory()->create(['role' => 'accounting']);
        $id = $this->actingAs($first)->getJson('/notifications')->json('latest_id');
        $this->actingAs($second)->getJson('/notifications')->assertJsonPath('unread_count', 1);
        $this->postJson('/notifications/'.$id.'/read')->assertNotFound();
        $this->record('electricity');
        $this->actingAs($first)->getJson('/notifications')->assertJsonPath('unread_count', 2);
        $this->postJson('/notifications/read-all', ['through_id' => $id])->assertOk();
        $this->getJson('/notifications?filter=unread')->assertJsonPath('unread_count', 1)->assertJsonPath('notifications.total', 1);
        $latest = $this->getJson('/notifications')->json('latest_id');
        $this->postJson('/notifications/read-all', ['through_id' => $latest])->assertOk();
        $this->getJson('/notifications?filter=unread')->assertJsonPath('unread_count', 0);
        $this->actingAs($second)->getJson('/notifications')->assertJsonPath('unread_count', 2);
        foreach (['page=0', 'filter=other'] as $query) $this->getJson('/notifications?'.$query)->assertUnprocessable();
        $this->postJson('/notifications/read-all')->assertUnprocessable();
        auth()->logout();
        $this->getJson('/notifications')->assertUnauthorized();
        $this->postJson('/notifications/'.$id.'/read')->assertUnauthorized();
    }
}
