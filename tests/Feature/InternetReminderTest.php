<?php

namespace Tests\Feature;

use App\Models\InternetSoa;
use App\Models\User;
use App\Notifications\InternetSoaReminder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InternetReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 09:00:00', 'Asia/Manila'));
        config(['mail.default' => 'smtp']);
    }

    private function renewal(string $date, string $status = 'awaiting_voucher'): InternetSoa
    {
        $record = new InternetSoa(['store_name' => 'BW Makati', 'due_date' => $date, 'amount' => '1500.50']);
        $record->attachment_path = 'internet-soas/statement.pdf';
        $record->attachment_name = 'statement.pdf';
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        $record->attachment_path = 'internet-soas/statement.pdf';
        $record->attachment_name = 'statement.pdf';
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
        $this->artisan('internet:send-soa-reminders')->assertSuccessful();
        $this->artisan('internet:send-soa-reminders')->assertSuccessful();
        foreach ($accountants as $user) {
            Notification::assertSentTo($user, InternetSoaReminder::class, function ($notification, $channels) use ($renewal, $user) {
                $mail = $notification->toMail($user);
                return $notification->renewal->id === $renewal->id && $channels === ['mail']
                    && $mail->actionUrl === route('internet-soa')
                    && in_array('Amount: PHP 1,500.50', $mail->introLines, true);
            });
            $this->assertDatabaseHas('internet_soa_reminders', ['user_id' => $user->id, 'email' => $user->email, 'due_date' => '2026-12-01']);
        }
        Notification::assertNotSentTo($other, InternetSoaReminder::class);
        Notification::assertCount(2);
        $this->assertSame(2, DB::table('internet_soa_reminders')->whereNotNull('sent_at')->count());
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
        $this->artisan('internet:send-soa-reminders')->assertSuccessful();
        Notification::assertSentToTimes($user, InternetSoaReminder::class, 2);
        $this->assertDatabaseCount('internet_soa_reminders', 2);
    }

    public function test_failed_mail_is_not_marked_sent_and_can_be_retried(): void
    {
        User::factory()->create(['role' => 'accounting']);
        $this->renewal('2026-12-01');
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->artisan('internet:send-soa-reminders')->assertFailed();
        $this->assertDatabaseCount('internet_soa_reminders', 0);
        Notification::fake();
        $this->artisan('internet:send-soa-reminders')->assertSuccessful();
        Notification::assertCount(1);
        $this->assertDatabaseCount('internet_soa_reminders', 1);
    }

    public function test_log_mailer_does_not_consume_real_email_reminders(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);
        User::factory()->create(['role' => 'accounting']);
        $this->renewal('2026-12-01');
        $this->artisan('internet:send-soa-reminders')->assertFailed();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('internet_soa_reminders', 0);
    }

}
