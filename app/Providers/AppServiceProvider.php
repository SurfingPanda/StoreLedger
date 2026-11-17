<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([\App\Models\User::class, \App\Models\NotificationSetting::class,
            \App\Models\Store::class, \App\Models\Supplier::class, \App\Models\StoreBillingAccount::class,
            \App\Models\PosRenewal::class, \App\Models\InternetSoa::class, \App\Models\BillingSoa::class,
            \App\Models\PosRenewalPayment::class, \App\Models\InternetSoaPayment::class, \App\Models\BillingSoaPayment::class,
            \App\Models\RecurringSchedule::class] as $model) $model::observe(\App\Observers\SecurityAuditObserver::class);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function ($event) {
            \App\Services\SecurityAudit::record('authentication', 'auth.signed_in', $event->user, actor: $event->user);
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Failed::class, function ($event) {
            \App\Services\SecurityAudit::record('authentication', 'auth.sign_in_failed', metadata: ['attempted_email' => is_string($event->credentials['email'] ?? null) ? $event->credentials['email'] : null], outcome: 'failure', anonymous: true);
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Logout::class, function ($event) {
            \App\Services\SecurityAudit::record('authentication', 'auth.signed_out', $event->user, actor: $event->user);
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\PasswordReset::class, function ($event) {
            \App\Services\SecurityAudit::record('authentication', 'auth.password_reset_completed', $event->user, actor: $event->user);
        });
    }
}
