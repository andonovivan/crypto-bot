<?php

namespace App\Console\Commands;

use App\Enums\PositionStatus;
use App\Models\Position;
use App\Models\Trade;
use App\Services\Exchange\ExchangeInterface;
use App\Services\FundingSettlementService;
use App\Services\Settings;
use App\Services\Strategy\StrategyInterface;
use App\Services\Strategy\StrategyRegistry;
use App\Services\TradingEngine;
use Illuminate\Console\Command;

class BotRun extends Command
{
    protected $signature = 'bot:run {--interval= : Scan interval in seconds (overrides settings)}';
    protected $description = 'Run the bot trading loop across all enabled strategies';

    private bool $shouldStop = false;

    private int $lastScannedCandleOpenTime = 0;

    public function handle(
        StrategyRegistry $registry,
        TradingEngine $engine,
        ExchangeInterface $exchange,
        FundingSettlementService $fundingService,
    ): int {
        $isDryRun = (bool) Settings::get('dry_run');
        $scanInterval = (int) ($this->option('interval') ?: Settings::get('scan_interval') ?: 30);

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $this->info($isDryRun ? 'Mode: DRY RUN (no real trades)' : 'Mode: LIVE TRADING');
        $this->info('Scan interval: ' . $scanInterval . 's');
        $this->info('Leverage: ' . (Settings::get('leverage') ?: 25) . 'x | Position: ' . (Settings::get('position_size_pct') ?: 10) . '% of balance');
        $this->info('Max positions (global cap): ' . (Settings::get('max_positions') ?: 10));
        $this->info('Funding tracking: ' . (Settings::get('funding_tracking_enabled') ? 'enabled' : 'disabled'));
        $this->newLine();

        $enabled = $registry->enabled();
        if (empty($enabled)) {
            $this->warn('No enabled strategies. Set strategy.<key>.enabled=true to activate one.');
        } else {
            $this->info('Enabled strategies (in cycle order):');
            foreach ($enabled as $s) {
                $this->info(sprintf('  - %s [%s] %s', $s->key(), $s->side(), $s->label()));
            }
            $this->newLine();
        }

        while (! $this->shouldStop) {
            try {
                $fundingService->settleFunding();
            } catch (\Throwable $e) {
                $this->error("Funding settlement error: {$e->getMessage()}");
            }

            try {
                $this->runCycle($registry, $engine, $exchange);
            } catch (\Throwable $e) {
                $this->error("Cycle error: {$e->getMessage()}");
            }

            // Align wakes to integer multiples of scan_interval since the
            // unix epoch, so cycles fire at predictable boundaries (e.g.
            // scan_interval=30 → :00 and :30 of every minute, scan_interval=60
            // → :00 only). The scanner gate inside runCycle still requires a
            // new closed 1m candle, so a wake at :00 is the one that actually
            // hits the scanner — matching backtest's exact-boundary tick.
            $now = microtime(true);
            $nextWake = ceil($now / $scanInterval) * $scanInterval;
            if ($nextWake <= $now) {
                $nextWake += $scanInterval;
            }
            $sleepTime = $nextWake - $now;
            $sleptSoFar = 0.0;
            while ($sleptSoFar < $sleepTime && ! $this->shouldStop) {
                $chunk = min(1.0, $sleepTime - $sleptSoFar);
                usleep((int) ($chunk * 1_000_000));
                $sleptSoFar += $chunk;
            }
        }

        $this->info('Bot stopped gracefully.');

        return self::SUCCESS;
    }

    private function runCycle(StrategyRegistry $registry, TradingEngine $engine, ExchangeInterface $exchange): void
    {
        $maxPositions = (int) Settings::get('max_positions') ?: 10;
        $paused = (bool) Settings::get('trading_paused');

        // Manage existing open positions first — strategy-agnostic.
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

        // Gate the scanner to once per new closed 1m candle so live entry
        // cadence matches `bot:backtest --use-1m`.
        $nowSec = now()->getTimestamp();
        $latestClosedOpenTime = (intdiv($nowSec, 60) - 1) * 60;
        if ($latestClosedOpenTime <= $this->lastScannedCandleOpenTime) {
            return;
        }
        $this->lastScannedCandleOpenTime = $latestClosedOpenTime;

        $currentOpen = Position::open()->count();
        if ($paused || $currentOpen >= $maxPositions) {
            $this->info(sprintf(
                'Cycle: skipped scan (open=%d/%d%s)',
                $currentOpen, $maxPositions, $paused ? ' paused' : ' at capacity'
            ));
            return;
        }

        $enabled = $registry->enabled();
        if (empty($enabled)) {
            return;
        }

        // Track symbols already opened this cycle so a later-priority strategy
        // doesn't fire on a symbol the first one just claimed. The actual
        // one-position-per-symbol invariant is also enforced by the engine,
        // but this avoids burning analyze() calls on duplicates.
        $handledSymbols = [];
        $totalCandidates = 0;
        $totalAnalyzed = 0;
        $totalOpened = 0;

        foreach ($enabled as $strategy) {
            if ($this->shouldStop || $paused) {
                break;
            }

            $strategyKey = $strategy->key();
            $cooldownMin = (int) Settings::get('strategy.'.$strategyKey.'.cooldown_minutes') ?: 120;
            $failedCooldownMin = max(0, (int) Settings::get('strategy.'.$strategyKey.'.failed_entry_cooldown_minutes'));

            $candidates = $strategy->getCandidates();
            $totalCandidates += count($candidates);
            $stratAnalyzed = 0;
            $stratOpened = 0;

            foreach ($candidates as $candidate) {
                if ($this->shouldStop) {
                    return;
                }

                $symbol = $candidate->symbol;

                // Already-handled-this-cycle dedupe (cross-strategy).
                if (isset($handledSymbols[$symbol])) {
                    continue;
                }

                if (Position::open()->count() >= $maxPositions) {
                    break 2; // exit both candidate and strategy loops
                }

                // Cross-strategy invariant: one open position per symbol
                // globally, regardless of which strategy owns it.
                if (Position::open()->where('symbol', $symbol)->exists()) {
                    continue;
                }

                // Per-(symbol, strategy) cooldown: a recent close by THIS
                // strategy blocks re-entry by this strategy. Other strategies
                // are unaffected.
                $recentClose = Trade::where('symbol', $symbol)
                    ->where('strategy_key', $strategyKey)
                    ->where('created_at', '>=', now()->subMinutes($cooldownMin))
                    ->exists();
                if ($recentClose) {
                    continue;
                }

                if ($failedCooldownMin > 0) {
                    $recentFail = Position::where('symbol', $symbol)
                        ->where('strategy_key', $strategyKey)
                        ->where('status', PositionStatus::Failed)
                        ->where('created_at', '>=', now()->subMinutes($failedCooldownMin))
                        ->exists();
                    if ($recentFail) {
                        continue;
                    }
                }

                $analysis = $strategy->analyze($symbol);
                $stratAnalyzed++;
                $totalAnalyzed++;

                if (! $analysis || ! $analysis->ok) {
                    continue;
                }

                $signal = $strategy->buildSignal($candidate, $analysis);

                $position = $engine->open($signal);
                if ($position) {
                    $stratOpened++;
                    $totalOpened++;
                    $handledSymbols[$symbol] = true;
                    $this->info(sprintf(
                        '  [%s] %s ENTRY @ %s (24h: %+.2f%%, reason: %s, strategy: %s)',
                        $symbol,
                        $signal->side,
                        $position->entry_price,
                        $candidate->priceChangePct,
                        $candidate->reason,
                        $strategyKey,
                    ));
                }
            }

            if (! empty($candidates)) {
                $this->info(sprintf(
                    '  strategy=%s candidates=%d analyzed=%d opened=%d',
                    $strategyKey,
                    count($candidates),
                    $stratAnalyzed,
                    $stratOpened,
                ));
            }
        }

        $this->info(sprintf(
            'Cycle: total candidates=%d analyzed=%d opened=%d openPositions=%d',
            $totalCandidates,
            $totalAnalyzed,
            $totalOpened,
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
        // Throttled to once per 15 min per symbol to avoid log spam.
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
