<?php

namespace App\Notifications;

use App\Models\PosRenewal;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class PosRenewalReminder extends Notification
{
    public function __construct(public PosRenewal $renewal) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('UTAK POS renewal reminder: '.$this->renewal->store_name)
            ->greeting('Hello '.($notifiable->name ?? 'Accounting team').',')
            ->line('A UTAK POS renewal is coming due and is awaiting Accounting voucher processing.')
            ->line('Store: '.$this->renewal->store_name)
            ->line('Renewal date: '.$this->renewal->renewal_date->format('F j, Y'))
            ->line('Amount: PHP '.number_format((float) $this->renewal->amount, 2))
            ->line('Record: POS-'.str_pad((string) $this->renewal->id, 5, '0', STR_PAD_LEFT))
            ->action('View Accounting renewal queue', route('utak-pos'))
            ->line('Please prepare the voucher before the renewal date.')
            ->salutation('BW Superbakeshop · Operations');
    }
}
