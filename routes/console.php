<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('pos:send-renewal-reminders')->dailyAt('09:00')
    ->timezone('Asia/Manila')->withoutOverlapping(60);

Schedule::command('internet:send-soa-reminders')->dailyAt('09:00')
    ->timezone('Asia/Manila')->withoutOverlapping(60);

Schedule::command('billing:send-soa-reminders')->dailyAt('09:00')
    ->timezone('Asia/Manila')->withoutOverlapping(60);
