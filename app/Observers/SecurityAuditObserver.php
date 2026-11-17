<?php
namespace App\Observers;

use App\Services\SecurityAudit;
use Illuminate\Database\Eloquent\Model;

class SecurityAuditObserver
{
    public function created(Model $model): void { $this->write($model, true); }
    public function updated(Model $model): void { $this->write($model, false); }
    private function write(Model $model, bool $created): void
    {
        if (!SecurityAudit::active()) return;
        $type = class_basename($model);
        $fields = match ($type) {
            'User' => ['name', 'email', 'role', 'is_active'],
            'NotificationSetting' => ['event', 'mode', 'emails'],
            default => SecurityAudit::FIELDS,
        };
        $changed = array_values(array_intersect(array_keys($model->getChanges()), $fields));
        $passwordChanged = $type === 'User' && $model->wasChanged('password');
        if (!$created && !$changed && !$passwordChanged) return;
        $before = $created ? [] : SecurityAudit::clean(collect($changed)->mapWithKeys(fn ($field) => [$field => $model->getOriginal($field)])->all(), $fields);
        $after = SecurityAudit::clean($model->only($created ? array_values(array_intersect(array_keys($model->getAttributes()), $fields)) : $changed), $fields);
        [$category, $event] = match ($type) {
            'User' => ['accounts', $created ? 'account.created' : ($passwordChanged ? 'account.password_changed' : 'account.updated')],
            'NotificationSetting' => ['notifications', 'notification.recipients_changed'],
            'Store', 'Supplier', 'StoreBillingAccount' => ['directory', $created ? 'directory.entry_created' : 'directory.entry_updated'],
            'RecurringSchedule' => ['automation', $created ? 'recurrence.created' : 'recurrence.updated'],
            'PosRenewalPayment', 'InternetSoaPayment', 'BillingSoaPayment' => ['billing', 'payment.recorded'],
            default => ['billing', $created ? 'request.created' : 'request.updated'],
        };
        if (!$created && in_array($type, ['PosRenewal', 'InternetSoa', 'BillingSoa'], true) && in_array('status', $changed)) {
            $event = match ($model->status) {
                'needs_revision' => $model->getRawOriginal('status') === 'cancelled' ? 'request.reopened' : 'request.returned',
                'awaiting_voucher' => $model->getRawOriginal('status') === 'needs_revision' ? 'request.resubmitted' : 'request.statement_added',
                'vouchered' => 'voucher.resubmitted', 'cancelled' => 'request.cancelled', 'paid' => 'request.marked_paid', default => 'request.updated',
            };
        }
        $metadata = ['changed_fields' => $changed];
        if ($passwordChanged) $metadata['password_changed'] = true;
        if ($type === 'User' && !$created && $model->wasChanged('session_version')) $metadata['sessions_revoked'] = true;
        if (str_ends_with($type, 'Payment')) $metadata['proof_uploaded'] = (bool) $model->proof_path;
        SecurityAudit::record($category, $event, $model, $before, $after, $metadata);
    }
}
