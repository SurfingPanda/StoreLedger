<?php

namespace Tests\Feature;

use App\Models\PosRenewal;
use App\Models\User;
use App\Notifications\PosRenewalReminder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PosReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 09:00:00', 'Asia/Manila'));
        config(['mail.default' => 'smtp']);
    }

    private function renewal(string $date, string $status = 'awaiting_voucher'): PosRenewal
    {
        $record = new PosRenewal(['store_name' => 'BW Makati', 'renewal_date' => $date, 'amount' => '1500.50']);
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        $record->status = $status;
        $record->save();
        return $record;
    }

    public function test_accounting_receives_due_reminders_once_at_registered_email(): void
    {
        Notification::fake();
        $accountants = User::factory()->count(2)->create(['role' => 'accounting']);
        $other = User::factory()->create(['role' => 'finance']);
        $renewal = $this->renewal('2026-12-01');
        $this->artisan('pos:send-renewal-reminders')->assertSuccessful();
        $this->artisan('pos:send-renewal-reminders')->assertSuccessful();
        foreach ($accountants as $user) {
            Notification::assertSentTo($user, PosRenewalReminder::class, function ($notification, $channels) use ($renewal, $user) {
                $mail = $notification->toMail($user);
                return $notification->renewal->id === $renewal->id && $channels === ['mail']
                    && $mail->actionUrl === route('utak-pos')
                    && in_array('Amount: PHP 1,500.50', $mail->introLines, true);
            });
            $this->assertDatabaseHas('pos_renewal_reminders', ['user_id' => $user->id, 'email' => $user->email, 'renewal_date' => '2026-12-01']);
        }
        Notification::assertNotSentTo($other, PosRenewalReminder::class);
        Notification::assertCount(2);
        $this->assertSame(2, DB::table('pos_renewal_reminders')->whereNotNull('sent_at')->count());
    }

    public function test_catchup_includes_short_notice_but_excludes_future_overdue_and_processed_records(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => 'accounting']);
        $this->renewal('2026-11-20');
        $this->renewal('2026-11-16');
        $this->renewal('2026-12-02');
        $this->renewal('2026-11-15');
        $this->renewal('2026-11-20', 'vouchered');
        $this->artisan('pos:send-renewal-reminders')->assertSuccessful();
        Notification::assertSentToTimes($user, PosRenewalReminder::class, 2);
        $this->assertDatabaseCount('pos_renewal_reminders', 2);
    }

    public function test_failed_mail_is_not_marked_sent_and_can_be_retried(): void
    {
        User::factory()->create(['role' => 'accounting']);
        $this->renewal('2026-12-01');
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->artisan('pos:send-renewal-reminders')->assertFailed();
        $this->assertDatabaseCount('pos_renewal_reminders', 0);
        Notification::fake();
        $this->artisan('pos:send-renewal-reminders')->assertSuccessful();
        Notification::assertCount(1);
        $this->assertDatabaseCount('pos_renewal_reminders', 1);
    }

    public function test_log_mailer_does_not_consume_real_email_reminders(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);
        User::factory()->create(['role' => 'accounting']);
        $this->renewal('2026-12-01');
        $this->artisan('pos:send-renewal-reminders')->assertFailed();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('pos_renewal_reminders', 0);
    }

    public function test_accounting_login_opens_queue_and_only_pending_records_are_returned(): void
    {
        $user = User::factory()->create(['role' => 'accounting', 'password' => 'long-test-password']);
        $pending = $this->renewal('2026-12-01');
        $this->renewal('2026-11-20', 'vouchered');
        $this->postJson('/login', ['email' => $user->email, 'password' => 'long-test-password'])
            ->assertOk()->assertJsonPath('redirect', route('utak-pos'));
        $this->getJson('/utak-pos/records')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $pending->id);
    }
}
