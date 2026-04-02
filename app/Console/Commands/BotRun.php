<?php

namespace App\Console\Commands;

use App\Services\PumpScanner;
use App\Services\Settings;
use App\Services\TradingEngine;
use App\Services\TrendScanner;
use Illuminate\Console\Command;

class BotRun extends Command
{
    protected $signature = 'bot:run {--interval= : Scan interval in seconds (overrides strategy default)} {--strategy= : Strategy: pump or trend (overrides settings)}';
    protected $description = 'Run the bot in a continuous loop (scan + trade + monitor)';

    private bool $shouldStop = false;

    public function handle(PumpScanner $pumpScanner, TrendScanner $trendScanner, TradingEngine $engine): int
    {
        $strategy = $this->option('strategy') ?: (string) Settings::get('strategy') ?: 'trend';
        $isDryRun = config('crypto.trading.dry_run');

        // Default intervals based on strategy
        $defaultInterval = $strategy === 'trend'
            ? (int) Settings::get('trend_scan_interval')
            : 300;
        $interval = (int) ($this->option('interval') ?: $defaultInterval);

        // Register signal handlers for graceful shutdown
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $this->info('Starting Crypto Trading Bot');
        $this->info("  Strategy: {$strategy}");
        $this->info($isDryRun ? '  Mode: DRY RUN (no real trades)' : '  Mode: LIVE TRADING');
        $this->info("  Scan interval: {$interval}s");
        $this->info("  Position size: " . config('crypto.trading.position_size_usdt') . " USDT");
        $this->info("  Max positions: " . config('crypto.trading.max_positions'));

        if ($strategy === 'trend') {
            $this->info("  Trend min score: " . Settings::get('trend_min_score'));
            $this->info("  Trend SL: " . Settings::get('trend_stop_loss_pct') . "% / TP: " . Settings::get('trend_take_profit_pct') . "%");
        } else {
            $this->info("  Stop-loss: " . config('crypto.trading.stop_loss_pct') . "%");
            $this->info("  Take-profit: " . config('crypto.trading.take_profit_pct') . "%");
        }
        $this->newLine();

        $monitorInterval = 60;
        $lastScanAt = 0;

        while (! $this->shouldStop) {
            $now = time();

            // Scan + trade on the full interval
            if ($now - $lastScanAt >= $interval) {
                $this->line('[' . now()->toDateTimeString() . "] Scanning ({$strategy})...");

                try {
                    if ($strategy === 'trend') {
                        $this->runTrendScan($trendScanner, $engine);
                    } else {
                        $this->runPumpScan($pumpScanner, $engine);
                    }
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

    private function runPumpScan(PumpScanner $scanner, TradingEngine $engine): void
    {
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

        // 2b. Retry confirmed signals
        $retried = $engine->retryConfirmedSignals();
        foreach ($retried as $position) {
            $this->info("  -> RETRY SHORT {$position->symbol} @ {$position->entry_price}");
        }

        // 3. Clean up
        $scanner->expireStaleSignals();
    }

    private function runTrendScan(TrendScanner $scanner, TradingEngine $engine): void
    {
        // 1. Scan for trend signals
        $signals = $scanner->scan();

        if ($signals->isNotEmpty()) {
            $this->info("  Trend signals: {$signals->count()}");
        }

        // 2. Open positions for detected signals
        foreach ($signals as $signal) {
            if ($signal->status->value !== 'detected') {
                continue; // Already traded or expired
            }

            $position = $engine->openPosition($signal, $signal->direction, 'trend_');
            if ($position) {
                $this->info("  -> {$position->side} {$position->symbol} @ {$position->entry_price} (score: {$signal->score})");
            }
        }

        // 3. Clean up
        $scanner->expireStaleSignals();
    }
}
