<?php

namespace App\Notifications;

use App\Services\RequestModule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowUpdate extends Notification
{
    public function __construct(public Model $record, public string $event) {}
    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $record = $this->record;
        $module = RequestModule::key($record);
        $paid = $this->event === 'rso_payment_processed';
        $finance = $this->event === 'finance_payment';
        $record->loadMissing(['voucherActor', 'payment.recordedBy']);
        $title = $finance ? 'Payment required' : ($paid ? 'Payment processed' : 'Voucher processed');
        $message = (new MailMessage)->subject($title.': '.RequestModule::LABELS[$module].' - '.$record->store_name)
            ->greeting($finance ? 'Hello Finance team,' : 'Hello RSO team,')
            ->line($paid ? 'Finance has recorded the payment. This request is now paid.' : ($finance ? 'Accounting has processed the voucher. This request is now awaiting Finance payment.' : 'Accounting processed the voucher and forwarded the request to Finance for payment.'))
            ->line('Category: '.RequestModule::LABELS[$module])->line('Store: '.$record->store_name)
            ->line('Amount: PHP '.number_format((float) $record->amount, 2))
            ->line('Due date: '.$record->{RequestModule::dateField($module)}->format('F j, Y'))
            ->line('Voucher reference: '.($record->voucher_reference ?: 'Not provided'));
        if ($paid && $record->payment) {
            $message->line('Payment reference: '.$record->payment->reference)
                ->line('Payment date: '.$record->payment->paid_on->format('F j, Y'))
                ->line('Recorded by: '.($record->payment->recordedBy?->name ?? 'Finance'));
        } else {
            $message->line('Processed by: '.($record->voucherActor?->name ?? 'Accounting'));
            if ($record->voucher_action_at) $message->line('Date vouchered: '.$record->voucher_action_at->timezone('Asia/Manila')->format('F j, Y g:i A'));
        }
        return $message->action($finance ? 'Review request & record payment' : ($paid ? 'View payment and proof' : 'View processed voucher'), route($module.'.detail', $record->id))
            ->line($finance ? 'Please enter the payment date, method, and reference, and upload proof of payment in StoreLedger.' : 'Open StoreLedger to review the request details, comments, and store history.')
            ->salutation('BW Superbakeshop Operations');
    }
}
