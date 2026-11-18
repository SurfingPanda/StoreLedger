<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use App\Services\RequestModule;

class NotificationSetting extends Model
{
    public const EVENTS = [
        'accounting_voucher' => 'accounting',
        'finance_payment' => 'finance',
        'rso_voucher_processed' => 'rso',
        'rso_payment_processed' => 'rso',
        'accounting_overdue' => 'accounting',
        'finance_overdue' => 'finance',
        'requester_overdue' => 'requester',
        'requester_revision' => 'requester',
        'accounting_revision' => 'accounting',
    ];
    protected $primaryKey = 'event';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['event', 'mode', 'emails'];
    protected function casts(): array { return ['emails' => 'array']; }

    public static function recipients(string $event, ?Model $record = null): Collection
    {
        $role = self::EVENTS[$event] ?? throw new \InvalidArgumentException('Unknown notification event');
        $setting = self::find($event);
        if ($setting?->mode === 'off') return collect();
        if ($setting?->mode === 'custom') {
            return collect($setting->emails)->map(fn ($email) => [
                'email' => $email, 'user_id' => null,
                'notifiable' => Notification::route('mail', $email),
            ]);
        }
        return self::teamRecipients($event, $record);
    }

    public static function teamRecipients(string $event, ?Model $record = null): Collection
    {
        $role = self::EVENTS[$event];
        $roles = $role !== 'requester' ? [$role] : (!$record || in_array(RequestModule::key($record), ['water', 'electricity', 'rental']) ? ['admin', 'rso'] : ['admin']);
        return User::whereIn('role', $roles)->where('is_active', true)->get()->map(fn ($user) => [
            'email' => $user->email, 'user_id' => $user->id, 'notifiable' => $user,
        ]);
    }
}
