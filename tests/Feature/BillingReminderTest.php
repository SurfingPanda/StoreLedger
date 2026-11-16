<?php

namespace Tests\Feature;

use App\Models\BillingSoa;
use App\Models\User;
use App\Notifications\BillingSoaReminder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BillingReminderTest extends TestCase
{
    use RefreshDatabase;
    private string $module = 'water';

    public static function modules(): array
    {
        return [['water'], ['electricity'], ['rental']];
    }


    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 09:00:00', 'Asia/Manila'));
        config(['mail.default' => 'smtp']);
    }

    private function renewal(string $date, string $status = 'awaiting_voucher'): BillingSoa
    {
        $record = new BillingSoa(['store_name' => 'BW Makati', 'due_date' => $date, 'amount' => '1500.50']);
        $record->module = $this->module;
        $record->attachment_path = 'internet-soas/statement.pdf';
        $record->attachment_name = 'statement.pdf';
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        $record->module = $this->module;
        $record->attachment_path = 'internet-soas/statement.pdf';
        $record->attachment_name = 'statement.pdf';
        $record->status = $status;
        $record->save();
        return $record;
    }

    #[DataProvider('modules')]
    public function test_accounting_receives_due_reminders_once_at_registered_email(string $module): void
    {
        $this->module = $module;
        Notification::fake();
        $accountants = User::factory()->count(2)->create(['role' => 'accounting']);
        $other = User::factory()->create(['role' => 'finance']);
        $renewal = $this->renewal('2026-12-01');
        $this->artisan('billing:send-soa-reminders')->assertSuccessful();
        $this->artisan('billing:send-soa-reminders')->assertSuccessful();
        foreach ($accountants as $user) {
            Notification::assertSentTo($user, BillingSoaReminder::class, function ($notification, $channels) use ($renewal, $user) {
                $mail = $notification->toMail($user);
                return $notification->renewal->id === $renewal->id && $channels === ['mail']
                    && $mail->actionUrl === route($this->module)
                    && in_array('Amount: PHP 1,500.50', $mail->introLines, true);
            });
            $this->assertDatabaseHas('billing_soa_reminders', ['user_id' => $user->id, 'email' => $user->email, 'due_date' => '2026-12-01']);
        }
        Notification::assertNotSentTo($other, BillingSoaReminder::class);
        Notification::assertCount(2);
        $this->assertSame(2, DB::table('billing_soa_reminders')->whereNotNull('sent_at')->count());
    }

    #[DataProvider('modules')]
    public function test_catchup_includes_short_notice_but_excludes_future_overdue_and_processed_records(string $module): void
    {
        $this->module = $module;
        Notification::fake();
        $user = User::factory()->create(['role' => 'accounting']);
        $this->renewal('2026-11-20');
        $this->renewal('2026-11-16');
        $this->renewal('2026-12-02');
        $this->renewal('2026-11-15');
        $this->renewal('2026-11-20', 'vouchered');
        $this->artisan('billing:send-soa-reminders')->assertSuccessful();
        Notification::assertSentToTimes($user, BillingSoaReminder::class, 2);
        $this->assertDatabaseCount('billing_soa_reminders', 2);
    }

    #[DataProvider('modules')]
    public function test_failed_mail_is_not_marked_sent_and_can_be_retried(string $module): void
    {
        $this->module = $module;
        User::factory()->create(['role' => 'accounting']);
        $this->renewal('2026-12-01');
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->artisan('billing:send-soa-reminders')->assertFailed();
        $this->assertDatabaseCount('billing_soa_reminders', 0);
        Notification::fake();
        $this->artisan('billing:send-soa-reminders')->assertSuccessful();
        Notification::assertCount(1);
        $this->assertDatabaseCount('billing_soa_reminders', 1);
    }

    #[DataProvider('modules')]
    public function test_log_mailer_does_not_consume_real_email_reminders(string $module): void
    {
        $this->module = $module;
        Notification::fake();
        config(['mail.default' => 'log']);
        User::factory()->create(['role' => 'accounting']);
        $this->renewal('2026-12-01');
        $this->artisan('billing:send-soa-reminders')->assertFailed();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('billing_soa_reminders', 0);
    }

}
