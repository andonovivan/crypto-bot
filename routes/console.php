<?php

use Illuminate\Support\Facades\Schedule;

// Scan for pumps every 5 minutes
Schedule::command('bot:scan --trade')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/bot-scan.log'));

// Monitor positions every minute
Schedule::command('bot:monitor')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/bot-monitor.log'));
