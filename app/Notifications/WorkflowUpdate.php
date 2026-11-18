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
        if (in_array($this->event, ['accounting_voucher', 'requester_revision', 'accounting_revision', 'accounting_overdue', 'finance_overdue', 'requester_overdue'], true)) {
            return $this->actionMail();
        }
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
    private function actionMail(): MailMessage
    {
        $record = $this->record;
        $module = RequestModule::key($record);
        $overdue = str_ends_with($this->event, '_overdue');
        $revision = in_array($this->event, ['requester_revision', 'accounting_revision'], true);
        $team = str_starts_with($this->event, 'finance_') ? 'Finance' : (str_starts_with($this->event, 'accounting_') ? 'Accounting' : 'Admin / RSO');
        $title = $overdue ? 'Overdue bill - action required' : ($revision ? 'Request returned for correction' : 'Corrected request ready for Accounting');
        $next = match ($this->event) {
            'finance_overdue' => 'Record payment and attach proof after settling this overdue bill.',
            'accounting_overdue' => $record->status === 'needs_revision' ? 'Correct the returned voucher and resubmit it to Finance.' : 'Review this overdue request and process its voucher.',
            'requester_overdue' => $record->status === 'awaiting_soa' ? 'Attach the current SOA and confirm the amount.' : 'Correct the returned bill and resubmit it to Accounting.',
            'accounting_revision' => 'Correct the voucher reference and resubmit the request to Finance.',
            'requester_revision' => 'Review the return reason, correct the bill, and resubmit it to Accounting.',
            default => 'Review the corrected request and process its voucher.',
        };
        $mail = (new MailMessage)->subject($title.': '.RequestModule::LABELS[$module].' - '.$record->store_name)
            ->greeting('Hello '.$team.' team,')->line($next)
            ->line('Store: '.$record->store_name)->line('Category: '.RequestModule::LABELS[$module])
            ->line('Amount: PHP '.number_format((float) $record->amount, 2))
            ->line('Due date: '.$record->{RequestModule::dateField($module)}->format('F j, Y'));
        if ($record->revision_reason) $mail->line('Return reason: '.$record->revision_reason);
        if ($overdue) $mail->line('This daily reminder continues while this bill remains overdue and requires action from your team.');
        return $mail->action('Review request', route($module.'.detail', $record->id))->salutation('BW Superbakeshop Operations');
    }

}
