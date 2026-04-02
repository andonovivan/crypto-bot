<?php

namespace App\Console\Commands;

use App\Enums\CloseReason;
use App\Enums\PositionStatus;
use App\Models\Position;
use App\Services\Exchange\ExchangeInterface;
use App\Services\Settings;
use App\Services\TradingEngine;
use App\Services\WaveScanner;
use App\Services\WaveSignal;
use Illuminate\Console\Command;

class BotRun extends Command
{
    protected $signature = 'bot:run {--interval= : Scan interval in seconds (overrides settings)}';
    protected $description = 'Run the Wave Rider bot in a continuous loop';

    private bool $shouldStop = false;

    public function handle(WaveScanner $waveScanner, TradingEngine $engine, ExchangeInterface $exchange): int
    {
        $isDryRun = config('crypto.trading.dry_run');
        $scanInterval = (int) ($this->option('interval') ?: Settings::get('wave_scan_interval'));

        // Register signal handlers for graceful shutdown
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $watchlist = $waveScanner->getWatchlist();

        $this->info('Starting Wave Rider Bot');
        $this->info($isDryRun ? '  Mode: DRY RUN (no real trades)' : '  Mode: LIVE TRADING');
        $this->info("  Watchlist: " . implode(', ', $watchlist));
        $this->info("  Scan interval: {$scanInterval}s");
        $this->info("  EMA: " . Settings::get('wave_ema_fast') . "/" . Settings::get('wave_ema_slow'));
        $this->info("  RSI: " . Settings::get('wave_rsi_period') . " (OB:" . Settings::get('wave_rsi_overbought') . " OS:" . Settings::get('wave_rsi_oversold') . ")");
        $this->info("  SL: " . Settings::get('wave_sl_atr_multiplier') . "x ATR | TP: " . Settings::get('wave_tp_atr_multiplier') . "x ATR (max " . Settings::get('wave_max_tp_atr') . "x ATR)");
        $this->info("  Trailing: activate " . Settings::get('wave_trailing_activation_atr') . "x ATR, trail " . Settings::get('wave_trailing_distance_atr') . "x ATR");
        $this->info("  DCA: " . (Settings::get('dca_enabled') ? 'enabled (max ' . Settings::get('dca_max_layers') . ' layers, trigger ' . Settings::get('wave_dca_trigger_atr') . 'x ATR)' : 'disabled'));
        $this->info("  Max hold: " . Settings::get('wave_max_hold_minutes') . " min | Position: $" . Settings::get('position_size_usdt'));
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
     * Process a single symbol: analyze wave, manage position, or open new one.
     */
    private function processSymbol(
        string $symbol,
        WaveScanner $waveScanner,
        TradingEngine $engine,
        ExchangeInterface $exchange,
    ): void {
        // 1. Analyze current wave state (fetches klines, cached in-process)
        $wave = $waveScanner->analyze($symbol);

        // 2. Check for existing position
        $position = Position::open()->where('symbol', $symbol)->first();

        if ($position !== null) {
            // --- MANAGE EXISTING POSITION ---
            $currentPrice = $wave?->currentPrice ?? $exchange->getPrice($symbol);

            // Wave break check — EMA flipped against our position
            if (! $waveScanner->isWaveIntact($symbol, $position->side)) {
                $engine->closePosition($position, $currentPrice, CloseReason::WaveBreak);
                $this->warn("  [{$symbol}] WAVE BREAK — closed {$position->side} @ {$currentPrice}");
                return;
            }

            // SL / TP / trailing / expiry checks
            $engine->checkPosition($position, $currentPrice);

            // DCA check (only if position survived above checks)
            $position->refresh();
            if ($position->status === PositionStatus::Open && (bool) Settings::get('dca_enabled')) {
                $engine->checkDCA($position, $currentPrice);
            }

        } elseif ($wave !== null && $wave->waveState === 'new_wave') {
            // --- NEW ENTRY ---
            $signal = new WaveSignal(
                symbol: $symbol,
                direction: $wave->direction,
                atr_value: $wave->atr,
                currentPrice: $wave->currentPrice,
                rsi: $wave->rsi,
            );

            $position = $engine->openPosition($signal, $wave->direction);
            if ($position) {
                $this->info("  [{$symbol}] ENTRY {$wave->direction} @ {$position->entry_price} (RSI:{$wave->rsi} ATR:" . round($wave->atr, 6) . " gap:{$wave->emaGap}%)");
            }
        }
    }
}
