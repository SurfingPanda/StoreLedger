<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class NotificationSetting extends Model
{
    public const EVENTS = [
        'accounting_voucher' => 'accounting',
        'finance_payment' => 'finance',
        'rso_voucher_processed' => 'rso',
        'rso_payment_processed' => 'rso',
    ];
    protected $primaryKey = 'event';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['event', 'mode', 'emails'];
    protected function casts(): array { return ['emails' => 'array']; }

    public static function recipients(string $event): Collection
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
        return User::where('role', $role)->get()->map(fn ($user) => [
            'email' => $user->email, 'user_id' => $user->id, 'notifiable' => $user,
        ]);
    }
}
