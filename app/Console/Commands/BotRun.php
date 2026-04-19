<?php

namespace App\Console\Commands;

use App\Enums\PositionStatus;
use App\Models\Position;
use App\Models\Trade;
use App\Services\Exchange\ExchangeInterface;
use App\Services\FundingSettlementService;
use App\Services\Settings;
use App\Services\ShortScanner;
use App\Services\ShortSignal;
use App\Services\TradingEngine;
use Illuminate\Console\Command;

class BotRun extends Command
{
    protected $signature = 'bot:run {--interval= : Scan interval in seconds (overrides settings)}';
    protected $description = 'Run the Short-Scalp trading bot in a continuous loop';

    private bool $shouldStop = false;

    public function handle(ShortScanner $scanner, TradingEngine $engine, ExchangeInterface $exchange, FundingSettlementService $fundingService): int
    {
        $isDryRun = (bool) Settings::get('dry_run');
        $scanInterval = (int) ($this->option('interval') ?: Settings::get('scan_interval') ?: 30);

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $this->info('Strategy: Short-Scalp');
        $this->info($isDryRun ? '  Mode: DRY RUN (no real trades)' : '  Mode: LIVE TRADING');
        $this->info('  Scan interval: ' . $scanInterval . 's');
        $this->info('  Filter: 24h >= +' . (Settings::get('pump_threshold_pct') ?: 25) . '% OR <= -' . (Settings::get('dump_threshold_pct') ?: 10) . '%');
        $this->info('  Min volume: $' . number_format((float) (Settings::get('min_volume_usdt') ?: 10_000_000)));
        $this->info('  15m EMA: ' . (Settings::get('ema_fast') ?: 9) . '/' . (Settings::get('ema_slow') ?: 21));
        $this->info('  TP: ' . (Settings::get('take_profit_pct') ?: 2) . '% | SL: ' . (Settings::get('stop_loss_pct') ?: 1) . '%');
        $this->info('  Max hold: ' . (Settings::get('max_hold_minutes') ?: 120) . ' min | Cooldown: ' . (Settings::get('cooldown_minutes') ?: 120) . ' min');
        $this->info('  Leverage: ' . (Settings::get('leverage') ?: 25) . 'x | Position: ' . (Settings::get('position_size_pct') ?: 10) . '% of balance');
        $this->info('  Max positions: ' . (Settings::get('max_positions') ?: 10));
        $this->info('  Max 15m candle body: ' . (Settings::get('max_candle_body_pct') ?: 3) . '%');
        $this->info('  Funding tracking: ' . (Settings::get('funding_tracking_enabled') ? 'enabled' : 'disabled'));
        $this->newLine();

        while (! $this->shouldStop) {
            $loopStart = microtime(true);

            try {
                $fundingService->settleFunding();
            } catch (\Throwable $e) {
                $this->error("Funding settlement error: {$e->getMessage()}");
            }

            try {
                $this->runCycle($scanner, $engine, $exchange);
            } catch (\Throwable $e) {
                $this->error("Cycle error: {$e->getMessage()}");
            }

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

    private function runCycle(ShortScanner $scanner, TradingEngine $engine, ExchangeInterface $exchange): void
    {
        $maxPositions = (int) Settings::get('max_positions') ?: 10;
        $cooldown = (int) Settings::get('cooldown_minutes') ?: 120;
        $failedCooldown = (int) Settings::get('failed_entry_cooldown_minutes') ?: 360;
        $paused = (bool) Settings::get('trading_paused');

        // Manage existing open positions first
        $openPositions = Position::open()->get();
        foreach ($openPositions as $position) {
            if ($this->shouldStop) {
                return;
            }
            try {
                $price = $exchange->getPrice($position->symbol);
                $engine->checkPosition($position, $price);
            } catch (\Throwable $e) {
                $this->error("  [{$position->symbol}] check error: {$e->getMessage()}");
            }
        }

        // Safety reconcile: in live mode, Binance closes SL/TP and the
        // user-data ws worker is the primary reconciler. If a fill arrived
        // while the ws was down or we missed an event, this cross-check
        // catches DB positions that are flat on the exchange and reconciles
        // them via the bracket order status. Skipped in dry-run since the
        // DryRunExchange reads positions from the DB — the set always
        // matches by construction.
        if (! (bool) Settings::get('dry_run')) {
            $this->safetyReconcile($engine, $exchange);
        }

        $candidates = $scanner->getCandidates();
        $analyzed = 0;
        $opened = 0;

        foreach ($candidates as $candidate) {
            if ($this->shouldStop) {
                return;
            }

            if ($paused) {
                break;
            }

            $currentOpen = Position::open()->count();
            if ($currentOpen >= $maxPositions) {
                break;
            }

            if (Position::open()->where('symbol', $candidate->symbol)->exists()) {
                continue;
            }

            $recentClose = Trade::where('symbol', $candidate->symbol)
                ->where('created_at', '>=', now()->subMinutes($cooldown))
                ->exists();
            if ($recentClose) {
                continue;
            }

            $recentFail = Position::where('symbol', $candidate->symbol)
                ->where('status', PositionStatus::Failed)
                ->where('created_at', '>=', now()->subMinutes($failedCooldown))
                ->exists();
            if ($recentFail) {
                continue;
            }

            $analysis = $scanner->analyze15m($candidate->symbol);
            $analyzed++;

            if (! $analysis || ! $analysis->downtrendOk) {
                continue;
            }

            $signal = new ShortSignal(
                symbol: $candidate->symbol,
                priceChangePct: $candidate->priceChangePct,
                reason: $candidate->reason,
                atr: $analysis->atr,
            );

            $position = $engine->openShort($signal);
            if ($position) {
                $opened++;
                $this->info(sprintf(
                    '  [%s] SHORT ENTRY @ %s (24h: %+.2f%%, body %.2f%%, reason: %s)',
                    $candidate->symbol,
                    $position->entry_price,
                    $candidate->priceChangePct,
                    $analysis->candleBodyPct,
                    $candidate->reason,
                ));
            }
        }

        $this->info(sprintf(
            'Cycle: candidates=%d analyzed=%d opened=%d openPositions=%d',
            count($candidates),
            $analyzed,
            $opened,
            Position::open()->count(),
        ));
    }

    /** @var array<string, int> symbol => last-warn-unix-timestamp */
    private array $untrackedWarnedAt = [];

    private function safetyReconcile(TradingEngine $engine, ExchangeInterface $exchange): void
    {
        try {
            $exchangeOpen = $exchange->getOpenPositions();
        } catch (\Throwable $e) {
            $this->error("Safety reconcile: getOpenPositions error: {$e->getMessage()}");
            return;
        }

        $onExchange = [];
        foreach ($exchangeOpen as $p) {
            if (isset($p['symbol'])) {
                $onExchange[$p['symbol']] = $p;
            }
        }

        $dbSymbols = [];
        foreach (Position::open()->get() as $position) {
            if ($this->shouldStop) {
                return;
            }
            $dbSymbols[$position->symbol] = true;
            if (isset($onExchange[$position->symbol])) {
                continue;
            }
            $this->warn("  [{$position->symbol}] flat on exchange — reconciling from brackets");
            try {
                $engine->reconcileMissingPosition($position);
            } catch (\Throwable $e) {
                $this->error("  [{$position->symbol}] reconcile error: {$e->getMessage()}");
            }
        }

        // Reverse check: positions on Binance that we have no DB row for.
        // The bot does not adopt them (we don't know the intended SL/TP),
        // just warns so the operator can investigate. Throttle to once
        // per 15 minutes per symbol to avoid log spam.
        $now = time();
        foreach ($onExchange as $symbol => $ex) {
            if (isset($dbSymbols[$symbol])) {
                continue;
            }
            $last = $this->untrackedWarnedAt[$symbol] ?? 0;
            if ($now - $last < 900) {
                continue;
            }
            $this->untrackedWarnedAt[$symbol] = $now;
            \Illuminate\Support\Facades\Log::warning('Untracked Binance position detected', [
                'symbol' => $symbol,
                'quantity' => $ex['quantity'] ?? null,
                'entryPrice' => $ex['entryPrice'] ?? null,
                'unrealizedPnl' => $ex['unrealizedPnl'] ?? null,
                'note' => 'Position exists on Binance but has no matching DB row — not managed by the bot.',
            ]);
            $this->warn("  [{$symbol}] untracked Binance position (qty={$ex['quantity']}, entry={$ex['entryPrice']}) — not managed by bot");
        }
    }
}
