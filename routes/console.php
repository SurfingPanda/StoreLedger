<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('pos:send-renewal-reminders')->dailyAt('09:00')
    ->timezone('Asia/Manila')->withoutOverlapping(60);
