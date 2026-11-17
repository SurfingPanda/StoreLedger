<?php

use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => \App\Services\AutomationMonitor::heartbeat())->everyMinute()->name('automation:heartbeat');

Schedule::command('pos:send-renewal-reminders')->dailyAt('09:00')
    ->timezone('Asia/Manila')->withoutOverlapping(60);

Schedule::command('internet:send-soa-reminders')->dailyAt('09:00')
    ->timezone('Asia/Manila')->withoutOverlapping(60);

Schedule::command('billing:send-soa-reminders')->dailyAt('09:00')
    ->timezone('Asia/Manila')->withoutOverlapping(60);

Schedule::command('notifications:deliver-workflow')->everyMinute()->withoutOverlapping(10);

Schedule::command('billing:generate-recurring')->hourly()->withoutOverlapping(60);
