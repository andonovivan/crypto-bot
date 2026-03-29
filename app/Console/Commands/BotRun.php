<?php

namespace App\Console\Commands;

use App\Services\PumpScanner;
use App\Services\TradingEngine;
use Illuminate\Console\Command;

class BotRun extends Command
{
    protected $signature = 'bot:run {--interval=300 : Scan interval in seconds}';
    protected $description = 'Run the bot in a continuous loop (scan + trade + monitor)';

    private bool $shouldStop = false;

    public function handle(PumpScanner $scanner, TradingEngine $engine): int
    {
        $interval = (int) $this->option('interval');
        $isDryRun = config('crypto.trading.dry_run');

        // Register signal handlers for graceful shutdown
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $this->info('Starting Crypto Pump & Dump Bot');
        $this->info($isDryRun ? '  Mode: DRY RUN (no real trades)' : '  Mode: LIVE TRADING');
        $this->info("  Scan interval: {$interval}s");
        $this->info("  Position size: " . config('crypto.trading.position_size_usdt') . " USDT");
        $this->info("  Stop-loss: " . config('crypto.trading.stop_loss_pct') . "%");
        $this->info("  Take-profit: " . config('crypto.trading.take_profit_pct') . "%");
        $this->info("  Max positions: " . config('crypto.trading.max_positions'));
        $this->newLine();

        $monitorInterval = 60; // Monitor positions every 60 seconds
        $lastScanAt = 0;

        while (! $this->shouldStop) {
            $now = time();

            // Scan for pumps + trade on the full interval
            if ($now - $lastScanAt >= $interval) {
                $this->line('[' . now()->toDateTimeString() . '] Scanning...');

                try {
                    // 1. Scan for pumps
                    $signals = $scanner->scan();

                    if ($signals->isNotEmpty()) {
                        $this->info("  Pumps detected: {$signals->count()}");
                    }

                    // 2. Check for reversals and auto-trade
                    $reversals = $scanner->checkReversals();

                    foreach ($reversals as $signal) {
                        $this->warn("  Reversal: {$signal->symbol} (-{$signal->drop_from_peak_pct}% from peak)");

                        $position = $engine->openShort($signal);
                        if ($position) {
                            $this->info("  -> SHORT {$position->symbol} @ {$position->entry_price}");
                        }
                    }

                    // 2b. Retry confirmed signals that weren't traded yet
                    // (e.g. previous position was stopped out, max positions was reached, etc.)
                    $retried = $engine->retryConfirmedSignals();
                    foreach ($retried as $position) {
                        $this->info("  -> RETRY SHORT {$position->symbol} @ {$position->entry_price}");
                    }

                    // 3. Clean up
                    $scanner->expireStaleSignals();

                } catch (\Throwable $e) {
                    $this->error("  Scan error: {$e->getMessage()}");
                }

                $lastScanAt = $now;
            }

            // Monitor positions every cycle (60s)
            try {
                $engine->monitorPositions();
            } catch (\Throwable $e) {
                $this->error("  Monitor error: {$e->getMessage()}");
            }

            // Sleep in 1-second increments so we can catch shutdown signals promptly
            for ($i = 0; $i < $monitorInterval && ! $this->shouldStop; $i++) {
                sleep(1);
            }
        }

        $this->info('Bot stopped gracefully.');

        return self::SUCCESS;
    }
}
