<?php

namespace App\Console\Commands;

use App\Enums\PositionStatus;
use App\Models\Position;
use App\Models\Trade;
use App\Services\Exchange\ExchangeInterface;
use App\Services\Exchange\HistoricalReplayExchange;
use App\Services\FundingSettlementService;
use App\Services\Settings;
use App\Services\ShortScanner;
use App\Services\ShortSignal;
use App\Services\TradingEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Replays historical kline data through the full bot stack (ShortScanner +
 * TradingEngine) to measure strategy performance over a past window. Uses
 * Carbon::setTestNow to mock the clock so the existing engine code runs
 * unchanged; positions/trades are written with is_dry_run=true.
 */
class BotBacktest extends Command
{
    protected $signature = 'bot:backtest
        {--from= : Start date YYYY-MM-DD (required)}
        {--to= : End date YYYY-MM-DD exclusive (default: from + 30 days)}
        {--symbols= : Comma-separated symbol filter (default: all with data)}
        {--starting-balance=10000 : Simulated USDT starting wallet}
        {--fixed-sizing : Pin wallet balance to starting balance so compounding does not distort per-trade stats}
        {--truncate : Wipe dry-run Position/Trade rows before starting}
        {--override=* : Repeatable key=value Settings::override() pair (e.g. --override=stop_loss_pct=1.5 --override=atr_sl_multiplier=2.0)}';

    protected $description = 'Replay historical klines through the strategy and report P&L';

    public function handle(): int
    {
        $fromStr = (string) $this->option('from');
        if (! $fromStr) {
            $this->error('--from is required (YYYY-MM-DD)');
            return self::FAILURE;
        }
        $toStr = (string) ($this->option('to') ?: Carbon::parse($fromStr)->addDays(30)->toDateString());
        $startingBalance = (float) $this->option('starting-balance');
        $symbolFilter = $this->option('symbols')
            ? array_filter(array_map('trim', explode(',', $this->option('symbols'))))
            : null;

        $fromMs = Carbon::parse($fromStr, 'UTC')->startOfDay()->getTimestampMs();
        $toMs = Carbon::parse($toStr, 'UTC')->startOfDay()->getTimestampMs();

        if ($toMs <= $fromMs) {
            $this->error('--to must be after --from');
            return self::FAILURE;
        }

        if ($this->option('truncate')) {
            $this->warn('Truncating dry-run positions/trades…');
            Trade::where('is_dry_run', true)->delete();
            Position::where('is_dry_run', true)->delete();
            // Circuit-breaker state can stick around from a prior run; wipe
            // it so this backtest starts with a clean risk-control slate.
            \Illuminate\Support\Facades\Cache::forget('circuit_breaker:cooldown_until');
            \Illuminate\Support\Facades\Cache::forget('circuit_breaker:measurement_start');
            \Illuminate\Support\Facades\Cache::forget('circuit_breaker:equity_peak');
        }

        // Process-local overrides — shadow the DB for this PHP process only so the
        // live bot container (which shares the bot_settings table) isn't flipped
        // into dry_run mode mid-flight. Also force trading_paused=false so a
        // dashboard-paused live bot doesn't silently block all backtest entries.
        Settings::override('dry_run', true);
        Settings::override('starting_balance', $startingBalance);
        Settings::override('trading_paused', false);

        $overrideOpts = (array) $this->option('override');
        foreach ($overrideOpts as $pair) {
            if (! str_contains($pair, '=')) {
                $this->error("--override must be key=value (got: {$pair})");
                return self::FAILURE;
            }
            [$k, $v] = array_map('trim', explode('=', $pair, 2));
            $meta = Settings::KEYS[$k] ?? null;
            if (! $meta) {
                $this->error("Unknown setting key: {$k}");
                return self::FAILURE;
            }
            $typed = match ($meta['type']) {
                'int' => (int) $v,
                'float' => (float) $v,
                'bool' => in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true),
                default => $v,
            };
            Settings::override($k, $typed);
            $this->info(sprintf('override: %s = %s', $k, json_encode($typed)));
        }

        try {
            $this->info(sprintf('Loading klines from DB for %s → %s%s…',
                $fromStr, $toStr, $symbolFilter ? ' (symbols: ' . implode(',', $symbolFilter) . ')' : ''));

            $replay = new HistoricalReplayExchange($fromMs, $toMs, $symbolFilter);
            $replay->setRealExchange(app(\App\Services\Exchange\BinanceExchange::class));
            if ((bool) $this->option('fixed-sizing')) {
                $replay->setFixedSizing(true);
                $this->info('Fixed-sizing mode: wallet balance pinned to starting balance (no compounding).');
            }
            $loadedSymbols = $replay->loadedSymbols();

            if (empty($loadedSymbols)) {
                $this->error('No klines loaded — run bot:download-history first.');
                return self::FAILURE;
            }
            $this->info('Loaded data for ' . count($loadedSymbols) . ' symbols.');

            // Rebind the container so ShortScanner + TradingEngine + FundingSettlementService
            // resolve the replay exchange instead of the real dispatcher.
            app()->instance(ExchangeInterface::class, $replay);

            // Re-resolve services so they pick up the new binding (Laravel caches scoped
            // resolution, but fresh `make()` calls see the new instance).
            $scanner = app(ShortScanner::class);
            $engine = app(TradingEngine::class);
            $fundingService = app(FundingSettlementService::class);

            $tickMs = 15 * 60 * 1000;
            $totalTicks = intdiv($toMs - $fromMs, $tickMs);

            $this->info(sprintf('Running %d × 15m ticks…', $totalTicks));
            $bar = $this->output->createProgressBar($totalTicks);
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %status%');
            $bar->start();

            $openEvents = 0;
            $closeEvents = 0;
            $errors = 0;

            for ($clock = $fromMs; $clock < $toMs; $clock += $tickMs) {
                $replay->setClock($clock);
                $ts = Carbon::createFromTimestampMs($clock, 'UTC');
                Carbon::setTestNow($ts);

                try {
                    $fundingService->settleFunding();
                } catch (\Throwable $e) {
                    $errors++;
                }

                try {
                    $openPositions = Position::open()->where('is_dry_run', true)->get();
                    $openCount = $openPositions->count();
                    foreach ($openPositions as $position) {
                        $this->tickPosition($engine, $replay, $position);
                    }

                    $closeEvents += $openCount - Position::open()->where('is_dry_run', true)->count();

                    $opened = $this->scanForEntries($scanner, $engine, $replay);
                    $openEvents += $opened;
                } catch (\Throwable $e) {
                    $errors++;
                    if ($errors <= 5) {
                        $this->newLine();
                        $this->warn('Tick error: ' . $e->getMessage());
                    }
                }

                if ($clock % (6 * 3600 * 1000) < $tickMs) {
                    // Mark progress every ~6 simulated hours.
                    $bar->setMessage(sprintf('%s opened=%d closed=%d open=%d',
                        $ts->format('Y-m-d H:i'),
                        $openEvents,
                        $closeEvents,
                        Position::open()->where('is_dry_run', true)->count()
                    ), 'status');
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            // Force-close any positions still open at the final simulated bar.
            // Without this, the live bot container (which shares the DB and sees
            // is_dry_run=true positions) would pick them up and close them at
            // TODAY's price vs the March entry — skewing stats by orders of
            // magnitude on any symbol that has drifted far from its March price.
            $forcedClosed = $this->forceCloseStragglers($engine, $replay);
            if ($forcedClosed > 0) {
                $this->warn(sprintf('Force-closed %d stragglers at final simulated bar (CloseReason::Expired).', $forcedClosed));
            }

            $this->printSummary($fromStr, $toStr, $openEvents, $closeEvents, $errors, $startingBalance);

            return self::SUCCESS;
        } finally {
            Carbon::setTestNow();
            Settings::clearOverrides();
        }
    }

    /**
     * Close any is_dry_run positions still open at the final simulated bar so
     * the live bot doesn't inherit them. Uses the replay's last known price for
     * each symbol; marks the close as Expired since backtest-end is effectively
     * the hard cutoff.
     */
    private function forceCloseStragglers(TradingEngine $engine, HistoricalReplayExchange $replay): int
    {
        $closed = 0;
        foreach (Position::open()->where('is_dry_run', true)->get() as $position) {
            $price = $replay->getPrice($position->symbol);
            if ($price <= 0) {
                // No price data for this symbol — mark closed without a Trade row
                // to keep the DB consistent.
                $position->update([
                    'status' => \App\Enums\PositionStatus::Expired,
                    'closed_at' => now(),
                ]);
                $closed++;
                continue;
            }
            $replay->setProbePrice($position->symbol, $price);
            try {
                $engine->closePosition($position, $price, \App\Enums\CloseReason::Expired);
                $closed++;
            } catch (\Throwable $e) {
                $this->warn(sprintf('forceClose %s failed: %s', $position->symbol, $e->getMessage()));
            } finally {
                $replay->clearProbePrice($position->symbol);
            }
        }
        return $closed;
    }

    /**
     * Advance one position through the current 15m bar using intra-bar high/low
     * to catch SL/TP wicks that the close-price alone would miss. Bar color
     * heuristic: red bar → high precedes low, green bar → low precedes high.
     *
     * Sets a symbol-scoped probe price on the replay exchange for the duration
     * of each probe so that any close order triggered by checkPosition executes
     * AT the probe (trigger) price, not at the 15m bar close. Without this,
     * SLs on red bars would fill at bar close after the wick, systematically
     * exaggerating losses.
     */
    private function tickPosition(TradingEngine $engine, HistoricalReplayExchange $replay, Position $position): void
    {
        $bar = $replay->getCurrentBar15m($position->symbol);
        if (! $bar) {
            // No bar data for this symbol at this tick (delisted, gap, etc.).
            // Skip — position remains open and will retry next tick.
            return;
        }

        $red = $bar['c'] < $bar['o'];
        $probes = $red
            ? [$bar['h'], $bar['l'], $bar['c']]
            : [$bar['l'], $bar['h'], $bar['c']];

        try {
            foreach ($probes as $price) {
                $fresh = Position::find($position->id);
                if (! $fresh || $fresh->status !== PositionStatus::Open) {
                    return;
                }

                // Refresh SL/TP each iteration: trailing TP ratchets stop_loss_price
                // mid-bar (e.g. on the L probe of a green bar), and the next probe
                // must clamp against the tightened stop, not the bar-start value.
                $sl = (float) $fresh->stop_loss_price;
                $tp = (float) $fresh->take_profit_price;

                // Clamp the probe price to the SL/TP trigger when it crosses
                // one. In live, Binance's STOP_MARKET / TAKE_PROFIT_MARKET fills
                // at the trigger (plus minor slippage); the raw intra-bar
                // high/low overshoots materially on volatile coins and
                // exaggerates SL losses well beyond what live would realise.
                $fillPrice = $price;
                if ($fresh->side === 'SHORT') {
                    if ($sl > 0 && $price >= $sl) {
                        $fillPrice = $sl;
                    } elseif ($tp > 0 && $price <= $tp) {
                        $fillPrice = $tp;
                    }
                } else { // LONG
                    if ($sl > 0 && $price <= $sl) {
                        $fillPrice = $sl;
                    } elseif ($tp > 0 && $price >= $tp) {
                        $fillPrice = $tp;
                    }
                }

                $replay->setProbePrice($position->symbol, $fillPrice);
                $engine->checkPosition($fresh, $fillPrice);
            }
        } finally {
            $replay->clearProbePrice($position->symbol);
        }
    }

    private function scanForEntries(ShortScanner $scanner, TradingEngine $engine, HistoricalReplayExchange $replay): int
    {
        $maxPositions = (int) Settings::get('max_positions') ?: 10;
        $cooldown = (int) Settings::get('cooldown_minutes') ?: 120;
        $failedCooldown = (int) Settings::get('failed_entry_cooldown_minutes') ?: 360;
        $paused = (bool) Settings::get('trading_paused');
        if ($paused) {
            return 0;
        }

        $opened = 0;
        $candidates = $scanner->getCandidates();

        foreach ($candidates as $candidate) {
            $currentOpen = Position::open()->where('is_dry_run', true)->count();
            if ($currentOpen >= $maxPositions) {
                break;
            }
            if (Position::open()->where('is_dry_run', true)->where('symbol', $candidate->symbol)->exists()) {
                continue;
            }
            $recentClose = Trade::where('is_dry_run', true)
                ->where('symbol', $candidate->symbol)
                ->where('created_at', '>=', now()->subMinutes($cooldown))
                ->exists();
            if ($recentClose) {
                continue;
            }
            $recentFail = Position::where('is_dry_run', true)
                ->where('symbol', $candidate->symbol)
                ->where('status', PositionStatus::Failed)
                ->where('created_at', '>=', now()->subMinutes($failedCooldown))
                ->exists();
            if ($recentFail) {
                continue;
            }

            $analysis = $scanner->analyze15m($candidate->symbol);
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
            }
        }

        return $opened;
    }

    private function printSummary(string $from, string $to, int $opens, int $closes, int $errors, float $startingBalance): void
    {
        $trades = Trade::where('is_dry_run', true)->get();

        $totalPnl = (float) $trades->sum('pnl');
        $totalFees = (float) $trades->sum('fees');
        $totalFunding = (float) $trades->sum('funding_fee');

        // When --fixed-sizing is on, exchange->getAccountData() returns the
        // pinned starting balance (not a live tally). Compute the true closing
        // wallet from realized P&L + funding so the summary reflects what the
        // strategy actually earned.
        $fixedSizing = (bool) $this->option('fixed-sizing');
        $finalWallet = $fixedSizing
            ? $startingBalance + $totalPnl + $totalFunding
            : (float) app(ExchangeInterface::class)->getAccountData()['walletBalance'];
        $n = $trades->count();
        $wins = $trades->where('pnl', '>', 0)->count();
        $losses = $trades->where('pnl', '<=', 0)->count();
        $wr = $n > 0 ? ($wins / $n) * 100 : 0;
        $avgWin = $wins > 0 ? $trades->where('pnl', '>', 0)->avg('pnl') : 0;
        $avgLoss = $losses > 0 ? $trades->where('pnl', '<=', 0)->avg('pnl') : 0;

        $byReason = $trades->groupBy(fn ($t) => $t->close_reason?->value ?? 'unknown')
            ->map(fn ($grp) => ['n' => $grp->count(), 'pnl' => round((float) $grp->sum('pnl'), 2)]);

        $this->info(str_repeat('─', 72));
        $this->info(sprintf(' Backtest Summary: %s → %s', $from, $to));
        $this->info(str_repeat('─', 72));
        $this->info(sprintf(' Tick errors:           %d', $errors));
        $this->info(sprintf(' Entries opened:        %d', $opens));
        $this->info(sprintf(' Positions closed:      %d (recorded %d Trade rows)', $closes, $n));
        $this->info(sprintf(' Starting balance:      $%s', number_format($startingBalance, 2)));
        $this->info(sprintf(' Final wallet balance:  $%s%s',
            number_format($finalWallet, 2),
            $fixedSizing ? ' (fixed-sizing: realized-only, no compounding)' : ''));
        $this->info(sprintf(' Net P&L:               $%s (%+.2f%%)',
            number_format($totalPnl, 2),
            $startingBalance > 0 ? ($totalPnl / $startingBalance) * 100 : 0,
        ));
        $this->info(sprintf(' Total fees:            $%s', number_format($totalFees, 2)));
        $this->info(sprintf(' Funding (stubbed):     $%s', number_format($totalFunding, 2)));
        $this->info(sprintf(' Win rate:              %.2f%% (%d/%d)', $wr, $wins, $n));
        $this->info(sprintf(' Avg win / loss:        $%.2f / $%.2f', $avgWin ?: 0, $avgLoss ?: 0));
        if ($avgLoss && $avgLoss != 0) {
            $this->info(sprintf(' R:R realized:          %.2f', abs(($avgWin ?: 0) / $avgLoss)));
        }

        if ($byReason->isNotEmpty()) {
            $this->newLine();
            $this->info(' By close reason:');
            foreach ($byReason as $reason => $stats) {
                $this->info(sprintf('   %-25s %4d  $%s', $reason, $stats['n'], number_format($stats['pnl'], 2)));
            }
        }

        $failed = Position::where('is_dry_run', true)->where('status', PositionStatus::Failed)->count();
        if ($failed > 0) {
            $this->newLine();
            $this->warn(sprintf(' Failed entries during backtest: %d', $failed));
        }

        $this->info(str_repeat('─', 72));
    }
}
