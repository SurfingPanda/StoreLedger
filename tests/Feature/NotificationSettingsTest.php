<?php

namespace Tests\Feature;

use App\Models\NotificationSetting;
use App\Models\PosRenewal;
use App\Models\User;
use App\Notifications\PosRenewalReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_save_notification_recipients(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->withoutVite()->get('/settings')->assertOk();
        $this->getJson('/settings/notifications')->assertOk()->assertJsonCount(count(NotificationSetting::EVENTS), 'settings');
        $settings = $this->settings();
        $settings[0]['mode'] = 'custom';
        $settings[0]['emails'] = ['accounting-updates@example.com'];
        $this->postJson('/settings/notifications', ['settings' => $settings])->assertOk();
        $this->assertDatabaseCount('notification_settings', count(NotificationSetting::EVENTS));
        $this->assertSame(['accounting-updates@example.com'], NotificationSetting::find('accounting_voucher')->emails);
        $this->getJson('/settings/notifications')->assertOk()->assertJsonPath('settings.0.mode', 'custom');
    }

    private function settings(): array
    {
        return collect(NotificationSetting::EVENTS)->map(fn ($role, $event) => ['event' => $event, 'mode' => 'role', 'emails' => []])->values()->all();
    }

    public function test_other_roles_get_personal_settings_but_cannot_access_notification_settings(): void
    {
        $this->get('/settings')->assertRedirect('/login');
        foreach (['accounting', 'finance', 'rso', 'it'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->withoutVite()->get('/settings')->assertOk();
            $this->getJson('/settings/notifications')->assertForbidden();
            $this->postJson('/settings/notifications', ['settings' => $this->settings()])->assertForbidden();
        }
        $this->assertDatabaseCount('notification_settings', 0);
    }

    public function test_admin_can_save_and_read_all_recipient_preferences(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $settings = $this->settings();
        $settings[0]['mode'] = 'custom';
        $settings[0]['emails'] = ['VOUCHER@example.com', 'voucher@example.com'];
        $settings[1]['mode'] = 'off';
        $this->postJson('/settings/notifications', ['settings' => $settings])->assertOk();
        $this->assertSame(['voucher@example.com'], NotificationSetting::find('accounting_voucher')->emails);
        $this->assertSame('off', NotificationSetting::find('finance_payment')->mode);
        $this->getJson('/settings/notifications')->assertOk()->assertJsonCount(count(NotificationSetting::EVENTS), 'settings');
        $this->withoutVite()->get('/settings')->assertOk();
    }

    public function test_invalid_or_incomplete_preferences_are_not_partially_saved(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $settings = $this->settings();
        $settings[0]['mode'] = 'custom';
        $this->postJson('/settings/notifications', ['settings' => $settings])->assertUnprocessable();
        $settings[0]['emails'] = ['not-an-email'];
        $this->postJson('/settings/notifications', ['settings' => $settings])->assertUnprocessable();
        $settings[0]['emails'] = ['good@example.com'];
        $settings[1]['event'] = $settings[0]['event'];
        $this->postJson('/settings/notifications', ['settings' => $settings])->assertUnprocessable();
        $this->postJson('/settings/notifications', ['settings' => array_slice($this->settings(), 0, 3)])->assertUnprocessable();
        $this->assertDatabaseCount('notification_settings', 0);
    }

    public function test_custom_recipient_gets_reminder_once_and_role_mode_does_not_resend_same_address(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $user = User::factory()->create(['role' => 'accounting', 'email' => 'voucher@example.com']);
        $admin = User::factory()->create(['role' => 'admin']);
        $renewal = new PosRenewal(['store_name' => 'Test store', 'renewal_date' => now('Asia/Manila')->addDays(15)->toDateString(), 'amount' => '1500.00']);
        $renewal->created_by = $admin->id;
        $renewal->save();
        NotificationSetting::create(['event' => 'accounting_voucher', 'mode' => 'custom', 'emails' => [$user->email]]);
        $this->artisan('pos:send-renewal-reminders')->assertSuccessful();
        $this->artisan('pos:send-renewal-reminders')->assertSuccessful();
        Notification::assertSentOnDemand(PosRenewalReminder::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $user->email);
        Notification::assertCount(1);
        $this->assertDatabaseHas('pos_renewal_reminders', ['email' => $user->email, 'user_id' => null]);
        NotificationSetting::find('accounting_voucher')->update(['mode' => 'role']);
        $this->artisan('pos:send-renewal-reminders')->assertSuccessful();
        Notification::assertCount(1);
    }

    public function test_off_mode_disables_reminder_recipients(): void
    {
        User::factory()->create(['role' => 'accounting']);
        $this->assertCount(1, NotificationSetting::recipients('accounting_voucher'));
        NotificationSetting::create(['event' => 'accounting_voucher', 'mode' => 'off', 'emails' => []]);
        $this->assertCount(0, NotificationSetting::recipients('accounting_voucher'));
    }
}
