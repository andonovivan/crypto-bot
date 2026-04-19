<?php

use Illuminate\Support\Facades\Schedule;

// Equity-curve snapshots: every 5 minutes. Uses the ExchangeDispatcher
// which in live mode reads from /fapi/v2/account (cached 10s), and in
// dry-run derives balance from Trade/Position rows.
Schedule::command('bot:snapshot-balance')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
