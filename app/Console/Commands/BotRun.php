<?php

namespace App\Console\Commands;

use App\Models\Position;
use App\Models\Trade;
use App\Services\Exchange\ExchangeInterface;
use App\Services\Settings;
use App\Services\TradingEngine;
use App\Services\WaveScanner;
use App\Services\WaveSignal;
use Illuminate\Console\Command;

class BotRun extends Command
{
    protected $signature = 'bot:run {--interval= : Scan interval in seconds (overrides settings)}';
    protected $description = 'Run the Grid Trading bot in a continuous loop';

    private bool $shouldStop = false;

    public function handle(WaveScanner $waveScanner, TradingEngine $engine, ExchangeInterface $exchange): int
    {
        $isDryRun = config('crypto.trading.dry_run');
        $leverage = (int) Settings::get('leverage') ?: 10;
        $scanInterval = (int) ($this->option('interval') ?: Settings::get('grid_scan_interval') ?: 30);

        // Register signal handlers for graceful shutdown
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $watchlist = $waveScanner->getWatchlist();

        $this->info('Starting Grid Trading Bot');
        $this->info($isDryRun ? '  Mode: DRY RUN (no real trades)' : '  Mode: LIVE TRADING');
        $this->info("  Watchlist: " . implode(', ', $watchlist));
        $this->info("  Interval: " . (Settings::get('grid_kline_interval') ?: '1h') . " candles | Scan: {$scanInterval}s");
        $this->info("  EMA: " . (Settings::get('grid_ema_fast') ?: 5) . "/" . (Settings::get('grid_ema_slow') ?: 13) . " (trend direction)");
        $this->info("  Long TP: " . (Settings::get('grid_long_tp_pct') ?: 1.68) . "% | Long SL: " . (Settings::get('grid_long_sl_pct') ?: 5.0) . "%");
        $this->info("  Short TP: " . (Settings::get('grid_short_tp_pct') ?: 1.0) . "% | Short SL: " . (Settings::get('grid_short_sl_pct') ?: 2.0) . "%");
        $this->info("  Grid: max " . (Settings::get('grid_max_per_symbol') ?: 10) . " per symbol, " . (Settings::get('grid_spacing_pct') ?: 0.5) . "% spacing");
        $this->info("  Max positions: " . (Settings::get('max_positions') ?: 30) . " | Position: " . (Settings::get('position_size_pct') ?: 1) . "% of balance x {$leverage}x");
        $this->info("  Max hold: " . (Settings::get('grid_max_hold_minutes') ?: 1440) . " min | Cooldown: " . (Settings::get('grid_cooldown_minutes') ?: 1) . " min");
        $this->info("  RSI filter: " . (Settings::get('grid_rsi_filter') ? 'enabled' : 'disabled'));
        $this->newLine();

        while (! $this->shouldStop) {
            $loopStart = microtime(true);

            foreach ($watchlist as $symbol) {
                if ($this->shouldStop) {
                    break;
                }

                try {
                    $this->processSymbol($symbol, $waveScanner, $engine, $exchange);
                } catch (\Throwable $e) {
                    $this->error("  [{$symbol}] Error: {$e->getMessage()}");
                }
            }

            // Sleep in sub-second chunks for responsive shutdown
            $elapsed = microtime(true) - $loopStart;
            $sleepTime = max(0, $scanInterval - $elapsed);
            $sleptSoFar = 0;
            while ($sleptSoFar < $sleepTime && ! $this->shouldStop) {
                $chunk = min(1.0, $sleepTime - $sleptSoFar);
                usleep((int) ($chunk * 1_000_000));
                $sleptSoFar += $chunk;
            }
        }

        $this->info('Bot stopped gracefully.');

        return self::SUCCESS;
    }

    /**
     * Process a single symbol: manage all open positions, then evaluate new grid entry.
     */
    private function processSymbol(
        string $symbol,
        WaveScanner $waveScanner,
        TradingEngine $engine,
        ExchangeInterface $exchange,
    ): void {
        // 1. Analyze current market state (fetches klines, cached in-process)
        $skipRsi = ! Settings::get('grid_rsi_filter');
        $wave = $waveScanner->analyze($symbol, skipRsiFilter: $skipRsi);

        $currentPrice = $wave?->currentPrice ?? $exchange->getPrice($symbol);

        // --- PHASE 1: Manage ALL open positions for this symbol ---
        $positions = Position::open()->where('symbol', $symbol)->get();
        foreach ($positions as $position) {
            $engine->checkPosition($position, $currentPrice);
        }

        // --- PHASE 2: Evaluate new grid entry ---
        if ($wave === null) {
            return;
        }

        // Check EMA alignment (new_wave or riding)
        if (! in_array($wave->waveState, ['new_wave', 'riding'])) {
            return;
        }

        // Cooldown — don't re-enter too soon after a close
        $cooldown = (int) Settings::get('grid_cooldown_minutes') ?: 1;
        $recentClose = Trade::where('symbol', $symbol)
            ->where('created_at', '>=', now()->subMinutes($cooldown))
            ->exists();
        if ($recentClose) {
            return;
        }

        // Per-symbol position count check
        $maxPerSymbol = (int) Settings::get('grid_max_per_symbol') ?: 10;
        $symbolPositions = Position::open()->where('symbol', $symbol)->get();
        if ($symbolPositions->count() >= $maxPerSymbol) {
            return;
        }

        // Grid spacing: current price must be >= spacing_pct away from ALL existing entries
        $spacingPct = (float) Settings::get('grid_spacing_pct') ?: 0.5;
        foreach ($symbolPositions as $existing) {
            $distancePct = abs($currentPrice - $existing->entry_price) / $existing->entry_price * 100;
            if ($distancePct < $spacingPct) {
                return; // Too close to an existing grid entry
            }
        }

        // All checks passed — open new grid position
        $signal = new WaveSignal(
            symbol: $symbol,
            direction: $wave->direction,
            atr_value: $wave->atr,
            currentPrice: $wave->currentPrice,
            rsi: $wave->rsi,
        );

        $position = $engine->openPosition($signal, $wave->direction);
        if ($position) {
            $gridCount = $symbolPositions->count() + 1;
            $this->info("  [{$symbol}] GRID ENTRY {$wave->direction} @ {$position->entry_price} (RSI:{$wave->rsi} grid:{$gridCount}/{$maxPerSymbol})");
        }
    }
}
