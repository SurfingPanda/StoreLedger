<?php

namespace App\Notifications;

use App\Models\BillingSoa;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class BillingSoaReminder extends Notification
{
    public function __construct(public BillingSoa $renewal) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(ucfirst($this->renewal->module).' SOA reminder: '.$this->renewal->store_name)
            ->greeting('Hello '.($notifiable->name ?? 'Accounting team').',')
            ->line(ucfirst($this->renewal->module).' SOA is coming due and is awaiting Accounting voucher processing.')
            ->line('Store: '.$this->renewal->store_name)
            ->line('Due date: '.$this->renewal->due_date->format('F j, Y'))
            ->line('Amount: PHP '.number_format((float) $this->renewal->amount, 2))
            ->line('Record: SOA-'.str_pad((string) $this->renewal->id, 5, '0', STR_PAD_LEFT))
            ->action('View Accounting SOA queue', route($this->renewal->module))
            ->line('Please prepare the voucher before the due date.')
            ->salutation('BW Superbakeshop · Operations');
    }
}
