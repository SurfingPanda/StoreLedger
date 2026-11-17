<?php

namespace Tests\Feature;

use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class MailThemeTest extends TestCase
{
    public function test_reminder_emails_use_bw_colors_and_group_request_details(): void
    {
        $message = (new MailMessage)->subject('Finance payment reminder')
            ->greeting('Hello Finance team,')->line('Accounting has processed this voucher.')
            ->line('Store: BW Makati')->line('Amount due: PHP 2,020.05')
            ->line('Due date: November 24, 2026')->line('Voucher reference: VCH-001')
            ->action('Review SOA and record payment', 'https://example.com/request/1')
            ->line('Please attach proof of payment.');
        $html = (string) $message->render();
        $this->assertStringContainsString('BW SUPERBAKESHOP', $html);
        $this->assertStringContainsString('#102fb0', $html);
        $this->assertStringContainsString('#ff5706', $html);
        $this->assertStringContainsString('REQUEST DETAILS', $html);
        $this->assertStringContainsString('BW Makati', $html);
        $this->assertStringContainsString('PHP 2,020.05', $html);
        $this->assertStringContainsString('https://example.com/request/1', $html);
        $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());
        $this->assertStringContainsString('PHP 2,020.05', $text);
        $this->assertStringContainsString('Please attach proof of payment.', $text);
        $this->assertStringNotContainsString('<table', $text);
    }

    public function test_email_theme_also_supports_password_resets_and_escapes_store_html(): void
    {
        $message = (new MailMessage)->greeting('Hello!')->line('Reset your password to sign in.')
            ->action('Reset password', 'https://example.com/reset?token=test');
        $this->assertStringContainsString('Reset password', (string) $message->render());
        $message->line('Store: <script>alert(1)</script>');
        $html = (string) $message->render();
        $this->assertStringNotContainsString('<script>', $html);
    }
}
