<?php

namespace App\Http\Controllers;

use App\Enums\CloseReason;
use App\Enums\PositionStatus;
use App\Models\BalanceSnapshot;
use App\Models\Position;
use App\Models\Trade;
use App\Services\Exchange\ExchangeInterface;
use App\Services\Settings;
use App\Services\ShortScanner;
use App\Services\ShortSignal;
use App\Services\TradingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $page = $request->route()?->defaults['page'] ?? 'overview';

        return view('dashboard', ['page' => $page]);
    }

    public function data(ExchangeInterface $exchange): JsonResponse
    {
        // All trade-history aggregates filter on the current dry_run mode so
        // the summary reflects "this mode's" performance, not a mix of past
        // dry-run + live history accumulated over time.
        $isDryRun = (bool) Settings::get('dry_run');

        $openPositions = Position::open()
            ->where('is_dry_run', $isDryRun)
            ->orderByDesc('opened_at')
            ->get();

        if ($openPositions->isNotEmpty()) {
            try {
                foreach ($openPositions as $position) {
                    $currentPrice = $exchange->getPrice($position->symbol);
                    $isLong = $position->side === 'LONG';
                    $pnl = $isLong
                        ? round(($currentPrice - $position->entry_price) * $position->quantity, 4)
                        : round(($position->entry_price - $currentPrice) * $position->quantity, 4);
                    $position->update([
                        'current_price' => $currentPrice,
                        'unrealized_pnl' => $pnl,
                    ]);
                }
            } catch (\Throwable $e) {
                // Use last known prices if API fails
            }
        }

        // Win/loss is decided on net trade outcome (raw pnl already excludes
        // trading fees; funding_fee is the remaining piece). A trade with a
        // positive pnl but a larger funding payment is a real loss.
        $tradesScope = Trade::where('is_dry_run', $isDryRun);
        $totalPnl = (clone $tradesScope)->sum('pnl');
        $closedFunding = (clone $tradesScope)->sum('funding_fee');
        $totalTrades = (clone $tradesScope)->count();
        $winningTrades = (clone $tradesScope)
            ->whereRaw('(pnl + COALESCE(funding_fee, 0)) > 0')
            ->count();
        $losingTrades = (clone $tradesScope)
            ->whereRaw('(pnl + COALESCE(funding_fee, 0)) < 0')
            ->count();
        $winRate = $totalTrades > 0 ? round(($winningTrades / $totalTrades) * 100, 1) : 0;

        $totalInvested = $openPositions->sum('position_size_usdt');
        $totalFees = (clone $tradesScope)->sum('fees');

        $accountData = $exchange->getAccountData();

        $fundingRates = [];
        if ($openPositions->isNotEmpty()) {
            try {
                $fundingRates = $exchange->getFundingRates();
            } catch (\Throwable) {
                // non-critical
            }
        }

        $feeRateCache = [];

        $positionsData = $openPositions->map(function (Position $p) use ($exchange, &$feeRateCache, $fundingRates) {
            $currentPrice = $p->current_price ?? $p->entry_price;

            if (! isset($feeRateCache[$p->symbol])) {
                try {
                    $rates = $exchange->getCommissionRate($p->symbol);
                    $feeRateCache[$p->symbol] = $rates['taker'];
                } catch (\Throwable) {
                    $feeRateCache[$p->symbol] = (float) Settings::get('dry_run_fee_rate') ?: 0.0005;
                }
            }
            $takerRate = $feeRateCache[$p->symbol];

            $entryFee = $p->entry_price * $p->quantity * $takerRate;
            $exitFee = $currentPrice * $p->quantity * $takerRate;
            $estimatedFees = round($entryFee + $exitFee, 4);
            $fundingFee = $p->funding_fee ?? 0;
            $netPnl = round(($p->unrealized_pnl ?? 0) - $estimatedFees + $fundingFee, 4);

            return [
                'id' => $p->id,
                'symbol' => $p->symbol,
                'side' => $p->side,
                'entry_price' => $p->entry_price,
                'current_price' => $currentPrice,
                'quantity' => $p->quantity,
                'position_size_usdt' => $p->position_size_usdt,
                'unrealized_pnl' => $p->unrealized_pnl,
                'pnl_pct' => $p->entry_price > 0
                    ? round($p->side === 'LONG'
                        ? (($currentPrice - $p->entry_price) / $p->entry_price) * 100
                        : (($p->entry_price - $currentPrice) / $p->entry_price) * 100, 2)
                    : 0,
                'estimated_fees' => $estimatedFees,
                'funding_fee' => round($fundingFee, 4),
                'funding_rate' => $fundingRates[$p->symbol]['fundingRate'] ?? null,
                'net_pnl' => $netPnl,
                'stop_loss_price' => $p->stop_loss_price,
                'take_profit_price' => $p->take_profit_price,
                'leverage' => $p->leverage,
                'is_dry_run' => $p->is_dry_run,
                'opened_at' => $p->opened_at->timestamp,
                'expires_at' => $p->expires_at?->timestamp,
            ];
        });

        $openNetPnl = $positionsData->sum('net_pnl');
        $totalNetPnl = round($totalPnl + $closedFunding + $openNetPnl, 4);
        $totalFunding = round($positionsData->sum('funding_fee') + $closedFunding, 4);

        return response()->json([
            'positions' => $positionsData,
            'summary' => [
                'balance' => round($accountData['walletBalance'], 2),
                'wallet_balance' => round($accountData['walletBalance'], 2),
                'available_balance' => round($accountData['availableBalance'], 2),
                'margin_in_use' => round($accountData['positionMargin'], 2),
                'margin_balance' => round($accountData['marginBalance'], 2),
                'net_pnl' => $totalNetPnl,
                'total_fees' => round($totalFees + $positionsData->sum('estimated_fees'), 4),
                'total_funding' => $totalFunding,
                'total_invested' => round($totalInvested, 2),
                'open_positions' => $openPositions->count(),
                'total_trades' => $totalTrades,
                'winning_trades' => $winningTrades,
                'losing_trades' => $losingTrades,
                'win_rate' => $winRate,
                'dry_run' => $isDryRun,
                'trading_paused' => (bool) Settings::get('trading_paused'),
            ],
            'ts' => now()->timestamp,
        ]);
    }

    public function trades(Request $request): JsonResponse
    {
        $allowedPerPage = [25, 50, 100, 500];
        $perPage = (int) $request->input('per_page', 50);
        if (! in_array($perPage, $allowedPerPage, true)) {
            $perPage = 50;
        }
        $page = max(1, (int) $request->input('page', 1));

        $sortByRaw = (string) $request->input('sort_by', 'created_at');
        $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Whitelist: maps frontend keys to SQL columns or (for net_pnl) an expression.
        $sortMap = [
            'symbol'             => 'trades.symbol',
            'entry_price'        => 'trades.entry_price',
            'exit_price'         => 'trades.exit_price',
            'pnl'                => 'trades.pnl',
            'pnl_pct'            => 'trades.pnl_pct',
            'fees'               => 'trades.fees',
            'position_size_usdt' => 'positions.position_size_usdt',
            'opened_at'          => 'positions.opened_at',
            'created_at'         => 'trades.created_at',
        ];

        $query = Trade::query()
            ->leftJoin('positions', 'positions.id', '=', 'trades.position_id')
            ->select('trades.*');

        if ($sortByRaw === 'net_pnl') {
            $query->orderByRaw('(trades.pnl + COALESCE(trades.funding_fee, 0)) ' . $sortDir);
        } else {
            $column = $sortMap[$sortByRaw] ?? 'trades.created_at';
            $query->orderBy($column, $sortDir);
        }
        // Stable tiebreaker so paging is deterministic when the sort key has duplicates.
        $query->orderBy('trades.id', $sortDir);

        $total = Trade::count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        $trades = $query
            ->with('position')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return response()->json([
            'data' => $trades->map(fn (Trade $t) => [
                'id' => $t->id,
                'symbol' => $t->symbol,
                'side' => $t->side,
                'entry_price' => $t->entry_price,
                'exit_price' => $t->exit_price,
                'quantity' => $t->quantity,
                'pnl' => $t->pnl,
                'pnl_pct' => $t->pnl_pct,
                'fees' => $t->fees,
                'funding_fee' => $t->funding_fee,
                'close_reason' => $t->close_reason->value,
                'is_dry_run' => $t->is_dry_run,
                'position_size_usdt' => round((float) $t->quantity * (float) $t->entry_price, 4),
                'leverage' => $t->position?->leverage,
                'opened_at' => $t->position?->opened_at?->timestamp,
                'created_at' => $t->created_at->timestamp,
            ]),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'sort_by' => $sortByRaw,
            'sort_dir' => $sortDir,
        ]);
    }

    public function failedEntries(Request $request): JsonResponse
    {
        $allowedPerPage = [25, 50, 100, 500];
        $perPage = (int) $request->input('per_page', 50);
        if (! in_array($perPage, $allowedPerPage, true)) {
            $perPage = 50;
        }
        $page = max(1, (int) $request->input('page', 1));

        $sortByRaw = (string) $request->input('sort_by', 'opened_at');
        $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $sortMap = [
            'symbol' => 'symbol',
            'position_size_usdt' => 'position_size_usdt',
            'leverage' => 'leverage',
            'opened_at' => 'opened_at',
        ];
        $column = $sortMap[$sortByRaw] ?? 'opened_at';

        $query = Position::where('status', PositionStatus::Failed)
            ->orderBy($column, $sortDir)
            ->orderBy('id', $sortDir);

        $total = Position::where('status', PositionStatus::Failed)->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        $rows = $query
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Position $p) => [
                'id' => $p->id,
                'symbol' => $p->symbol,
                'side' => $p->side,
                'error_message' => $p->error_message,
                'position_size_usdt' => $p->position_size_usdt,
                'leverage' => $p->leverage,
                'is_dry_run' => $p->is_dry_run,
                'opened_at' => $p->opened_at?->timestamp,
            ]),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'sort_by' => $sortByRaw,
            'sort_dir' => $sortDir,
        ]);
    }

    public function scanNow(ShortScanner $scanner, TradingEngine $engine, Request $request): JsonResponse
    {
        $autoTrade = $request->boolean('auto_trade', false);
        $cooldown = (int) Settings::get('cooldown_minutes') ?: 120;
        $failedCooldown = (int) Settings::get('failed_entry_cooldown_minutes') ?: 360;
        $maxPositions = (int) Settings::get('max_positions') ?: 10;
        $paused = (bool) Settings::get('trading_paused');

        $candidates = $scanner->getCandidates();
        $results = [];
        $trades = [];

        foreach ($candidates as $candidate) {
            $analysis = $scanner->analyze15m($candidate->symbol);
            $blockedReasons = [];

            if ($paused) {
                $blockedReasons[] = 'Trading paused';
            }
            if (Position::open()->count() >= $maxPositions) {
                $blockedReasons[] = 'Max total positions';
            }
            if (Position::open()->where('symbol', $candidate->symbol)->exists()) {
                $blockedReasons[] = 'Already open';
            }
            if (Trade::where('symbol', $candidate->symbol)->where('created_at', '>=', now()->subMinutes($cooldown))->exists()) {
                $blockedReasons[] = "Cooldown ({$cooldown}m)";
            }
            if (Position::where('symbol', $candidate->symbol)
                ->where('status', PositionStatus::Failed)
                ->where('created_at', '>=', now()->subMinutes($failedCooldown))
                ->exists()
            ) {
                $blockedReasons[] = "Failed-entry cooldown ({$failedCooldown}m)";
            }
            if ($analysis && ! $analysis->downtrendOk) {
                $blockedReasons[] = $analysis->blockedReason ?? '15m trend not down';
            }
            if (! $analysis) {
                $blockedReasons[] = 'Insufficient klines';
            }

            $canEnter = empty($blockedReasons);

            $results[] = [
                'symbol' => $candidate->symbol,
                'price_change_pct' => $candidate->priceChangePct,
                'volume' => $candidate->volume,
                'price' => $candidate->price,
                'reason' => $candidate->reason,
                'ema_fast' => $analysis?->emaFast,
                'ema_slow' => $analysis?->emaSlow,
                'candle_body_pct' => $analysis?->candleBodyPct,
                'last_candle_red' => $analysis?->lastCandleRed,
                'funding_rate' => $analysis?->fundingRate,
                'downtrend_ok' => $analysis?->downtrendOk ?? false,
                'can_enter' => $canEnter,
                'blocked_reasons' => $blockedReasons,
            ];

            if ($autoTrade && $canEnter && $analysis) {
                $signal = new ShortSignal(
                    symbol: $candidate->symbol,
                    priceChangePct: $candidate->priceChangePct,
                    reason: $candidate->reason,
                );
                $position = $engine->openShort($signal);
                if ($position) {
                    $trades[] = $position->symbol;
                }
            }
        }

        return response()->json([
            'ok' => true,
            'candidates' => $results,
            'candidate_count' => count($results),
            'trades_opened' => $trades,
        ]);
    }

    public function closePosition(Request $request, TradingEngine $engine): JsonResponse
    {
        $request->validate(['position_id' => 'required|integer']);

        $position = Position::open()->findOrFail($request->position_id);
        $trade = $engine->closePosition($position, reason: \App\Enums\CloseReason::Manual);

        return response()->json([
            'ok' => true,
            'pnl' => $trade->pnl,
            'exit_price' => $trade->exit_price,
        ]);
    }

    public function closeAll(TradingEngine $engine): JsonResponse
    {
        $positions = Position::open()->get();
        $closed = 0;
        $failed = [];
        $totalPnl = 0.0;

        foreach ($positions as $position) {
            try {
                $trade = $engine->closePosition($position, reason: \App\Enums\CloseReason::Manual);
                $closed++;
                $totalPnl += (float) $trade->pnl;
            } catch (\Throwable $e) {
                $failed[] = ['symbol' => $position->symbol, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'ok' => empty($failed),
            'closed' => $closed,
            'failed' => $failed,
            'total_pnl' => round($totalPnl, 4),
        ]);
    }

    public function addToPosition(Request $request, TradingEngine $engine): JsonResponse
    {
        $request->validate([
            'position_id' => 'required|integer',
            'amount_usdt' => 'required|numeric|min:1',
        ]);

        $position = Position::open()->findOrFail($request->position_id);

        try {
            $updated = $engine->addToPosition($position, (float) $request->amount_usdt);

            return response()->json([
                'ok' => true,
                'position' => [
                    'id' => $updated->id,
                    'entry_price' => $updated->entry_price,
                    'quantity' => $updated->quantity,
                    'position_size_usdt' => $updated->position_size_usdt,
                    'stop_loss_price' => $updated->stop_loss_price,
                    'take_profit_price' => $updated->take_profit_price,
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function reversePosition(Request $request, TradingEngine $engine): JsonResponse
    {
        $request->validate(['position_id' => 'required|integer']);

        $position = Position::open()->findOrFail($request->position_id);

        try {
            $result = $engine->reversePosition($position);

            return response()->json([
                'ok' => true,
                'closed_trade' => [
                    'pnl' => $result['trade']->pnl,
                    'exit_price' => $result['trade']->exit_price,
                    'fees' => $result['trade']->fees,
                ],
                'new_position' => $result['position'] ? [
                    'id' => $result['position']->id,
                    'side' => $result['position']->side,
                    'entry_price' => $result['position']->entry_price,
                    'quantity' => $result['position']->quantity,
                    'stop_loss_price' => $result['position']->stop_loss_price,
                    'take_profit_price' => $result['position']->take_profit_price,
                ] : null,
                'warning' => $result['position'] === null
                    ? 'Position was closed but the reverse open failed. You may want to open a new position manually.'
                    : null,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function balanceHistory(Request $request): JsonResponse
    {
        $allowed = ['1h' => 1, '6h' => 6, '24h' => 24, '7d' => 24 * 7, '30d' => 24 * 30, 'all' => null];
        $rangeKey = (string) $request->input('range', '24h');
        if (! array_key_exists($rangeKey, $allowed)) {
            $rangeKey = '24h';
        }

        $isDryRun = (bool) Settings::get('dry_run');

        $query = BalanceSnapshot::where('is_dry_run', $isDryRun)
            ->orderBy('created_at', 'asc');

        if ($allowed[$rangeKey] !== null) {
            $query->where('created_at', '>=', now()->subHours($allowed[$rangeKey]));
        }

        $snapshots = $query->get(['wallet_balance', 'available_balance', 'unrealized_profit', 'margin_balance', 'position_margin', 'open_positions', 'created_at']);

        $startingBalance = (float) Settings::get('starting_balance');
        // For "all", the baseline is the configured deposit so the delta equals true lifetime P&L.
        // For finite ranges, the baseline is the first snapshot in the window (delta over the period).
        $baseline = $rangeKey === 'all'
            ? $startingBalance
            : (float) ($snapshots->first()?->wallet_balance ?? $startingBalance);

        return response()->json([
            'range' => $rangeKey,
            'is_dry_run' => $isDryRun,
            'baseline' => $baseline,
            'points' => $snapshots->map(fn (BalanceSnapshot $s) => [
                'ts' => $s->created_at->timestamp,
                'wallet_balance' => $s->wallet_balance,
                'available_balance' => $s->available_balance,
                'unrealized_profit' => $s->unrealized_profit,
                'margin_balance' => $s->margin_balance,
                'position_margin' => $s->position_margin,
                'open_positions' => $s->open_positions,
            ]),
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json([
            'settings' => Settings::all(),
            'groups' => Settings::groups(),
            'exchange' => [
                'driver' => config('crypto.exchange'),
                'testnet' => config('crypto.binance.testnet'),
            ],
        ]);
    }

    public function resetAll(): JsonResponse
    {
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Trade::truncate();
        Position::truncate();
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Any circuit-breaker cooldown was tied to the now-wiped trade
        // history; clearing it keeps the risk-control state consistent.
        \Illuminate\Support\Facades\Cache::forget('circuit_breaker:cooldown_until');
        \Illuminate\Support\Facades\Cache::forget('circuit_breaker:measurement_start');
        \Illuminate\Support\Facades\Cache::forget('circuit_breaker:equity_peak');

        return response()->json(['ok' => true]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $request->validate([
            'settings' => 'required|array',
            'settings.*' => 'required',
        ]);

        foreach ($request->input('settings') as $key => $value) {
            Settings::set($key, $value);
        }

        return response()->json(['ok' => true]);
    }

    public function scannerData(ShortScanner $scanner): JsonResponse
    {
        $cooldown = (int) Settings::get('cooldown_minutes') ?: 120;
        $failedCooldown = (int) Settings::get('failed_entry_cooldown_minutes') ?: 360;
        $maxPositions = (int) Settings::get('max_positions') ?: 10;
        $paused = (bool) Settings::get('trading_paused');

        $candidates = $scanner->getCandidates();
        $totalOpen = Position::open()->count();

        $rows = [];
        foreach ($candidates as $candidate) {
            $analysis = $scanner->analyze15m($candidate->symbol);
            $symbolOpen = Position::open()->where('symbol', $candidate->symbol)->count();
            $blockedReasons = [];

            if ($paused) {
                $blockedReasons[] = 'Trading paused';
            }
            if ($totalOpen >= $maxPositions) {
                $blockedReasons[] = "Max total ({$totalOpen}/{$maxPositions})";
            }
            if ($symbolOpen > 0) {
                $blockedReasons[] = 'Already open';
            }
            if (Trade::where('symbol', $candidate->symbol)->where('created_at', '>=', now()->subMinutes($cooldown))->exists()) {
                $blockedReasons[] = "Cooldown ({$cooldown}m)";
            }
            if (Position::where('symbol', $candidate->symbol)
                ->where('status', PositionStatus::Failed)
                ->where('created_at', '>=', now()->subMinutes($failedCooldown))
                ->exists()
            ) {
                $blockedReasons[] = "Failed-entry cooldown ({$failedCooldown}m)";
            }
            if (! $analysis) {
                $blockedReasons[] = 'No klines';
            } elseif (! $analysis->downtrendOk) {
                $blockedReasons[] = $analysis->blockedReason ?? '15m not down';
            }

            $rows[] = [
                'symbol' => $candidate->symbol,
                'price_change_pct' => $candidate->priceChangePct,
                'volume' => $candidate->volume,
                'price' => $candidate->price,
                'reason' => $candidate->reason,
                'ema_fast' => $analysis?->emaFast !== null ? round($analysis->emaFast, 6) : null,
                'ema_slow' => $analysis?->emaSlow !== null ? round($analysis->emaSlow, 6) : null,
                'candle_body_pct' => $analysis?->candleBodyPct,
                'last_candle_red' => $analysis?->lastCandleRed,
                'prior_candle_red' => $analysis?->priorCandleRed,
                'funding_rate' => $analysis?->fundingRate,
                'downtrend_ok' => $analysis?->downtrendOk ?? false,
                'open_positions' => $symbolOpen,
                'can_enter' => empty($blockedReasons),
                'blocked_reasons' => $blockedReasons,
            ];
        }

        return response()->json([
            'ok' => true,
            'candidates' => $rows,
            'scanned_at' => now()->timestamp,
        ]);
    }

    public function openPosition(Request $request, TradingEngine $engine): JsonResponse
    {
        $request->validate([
            'symbol' => 'required|string',
        ]);

        $symbol = strtoupper($request->input('symbol'));

        $signal = new ShortSignal(
            symbol: $symbol,
            priceChangePct: 0.0,
            reason: 'manual',
        );

        $position = $engine->openShort($signal);

        if (! $position) {
            return response()->json([
                'ok' => false,
                'message' => 'Could not open position. Check max positions, balance, or symbol tradability.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'position' => [
                'id' => $position->id,
                'symbol' => $position->symbol,
                'side' => $position->side,
                'entry_price' => $position->entry_price,
                'quantity' => $position->quantity,
                'position_size_usdt' => $position->position_size_usdt,
            ],
        ]);
    }

    public function stats(ExchangeInterface $exchange): JsonResponse
    {
        $isDryRun = (bool) Settings::get('dry_run');
        $now = now();

        $accountData = $exchange->getAccountData();
        $walletBalance = (float) $accountData['walletBalance'];
        $availableBalance = (float) $accountData['availableBalance'];
        $unrealizedProfit = (float) ($accountData['unrealizedProfit'] ?? 0);
        $equity = $walletBalance + $unrealizedProfit;

        // Drawdown is measured on equity (wallet + unrealized) — using
        // wallet_balance alone underestimates the peak whenever a position is
        // open with unrealized profit, and skips drawdowns that show up only
        // as unrealized losses.
        $snapshots = BalanceSnapshot::where('is_dry_run', $isDryRun)
            ->orderBy('created_at', 'asc')
            ->get(['wallet_balance', 'unrealized_profit', 'created_at']);

        $equity24hAgo = null;
        $cutoff24h = $now->copy()->subDay();
        foreach ($snapshots as $s) {
            if ($s->created_at->lessThanOrEqualTo($cutoff24h)) {
                $equity24hAgo = (float) $s->wallet_balance + (float) ($s->unrealized_profit ?? 0);
            } else {
                break;
            }
        }

        // Max-drawdown: walk the equity series tracking running peak and the
        // worst peak-to-trough ratio. Anchor the trough's date so the UI can
        // show the range, not just a number.
        $peak = 0.0;
        $maxDdPct = 0.0;
        $maxDdFrom = null;
        $maxDdTo = null;
        $peakAt = null;
        $latestSnapshotEquity = 0.0;
        foreach ($snapshots as $s) {
            $v = (float) $s->wallet_balance + (float) ($s->unrealized_profit ?? 0);
            $latestSnapshotEquity = $v;
            if ($v > $peak) {
                $peak = $v;
                $peakAt = $s->created_at;
            }
            if ($peak > 0) {
                $dd = ($peak - $v) / $peak * 100;
                if ($dd > $maxDdPct) {
                    $maxDdPct = $dd;
                    $maxDdFrom = $peakAt;
                    $maxDdTo = $s->created_at;
                }
            }
        }

        // Anchor the running peak against either the historical max equity or
        // the live equity (whichever is higher) so the current drawdown can
        // never go negative when we set a fresh ATH between snapshots.
        $runningPeak = max($peak, $equity);
        $currentDdPct = $runningPeak > 0 ? max(0.0, ($runningPeak - $equity) / $runningPeak * 100) : 0.0;

        $todayPnl = (float) Trade::where('is_dry_run', $isDryRun)
            ->where('created_at', '>=', $now->copy()->startOfDay())
            ->selectRaw('COALESCE(SUM(pnl), 0) + COALESCE(SUM(funding_fee), 0) AS total')
            ->value('total');

        // Profit factor: gross net wins / |gross net losses| over last 30d.
        // "Net" = pnl + funding_fee, matching the Realized column in the
        // History tab. Returns null when losses==0 so the UI shows "n/a"
        // instead of Infinity.
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $netExpr = '(pnl + COALESCE(funding_fee, 0))';
        $wins = (float) Trade::where('is_dry_run', $isDryRun)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->whereRaw("$netExpr > 0")
            ->selectRaw("COALESCE(SUM($netExpr), 0) AS s")
            ->value('s');
        $losses = (float) Trade::where('is_dry_run', $isDryRun)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->whereRaw("$netExpr < 0")
            ->selectRaw("COALESCE(SUM($netExpr), 0) AS s")
            ->value('s');
        $profitFactor = $losses < 0 ? round($wins / abs($losses), 2) : null;

        // Rolling win-rate: last 20 trades. The history series is the running
        // win-rate over the last 100 trades (most recent 100, walked oldest
        // → newest within that window so the sparkline reads left-to-right
        // chronologically).
        $recent20 = Trade::where('is_dry_run', $isDryRun)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['pnl', 'funding_fee']);
        $rollingWinRate = $recent20->count() > 0
            ? round($recent20->filter(fn ($t) => ((float) $t->pnl + (float) ($t->funding_fee ?? 0)) > 0)->count() / $recent20->count(), 3)
            : 0.0;

        $recent100 = Trade::where('is_dry_run', $isDryRun)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['pnl', 'funding_fee'])
            ->reverse()
            ->values();
        $winRateHistory = [];
        $window = [];
        foreach ($recent100 as $t) {
            $net = (float) $t->pnl + (float) ($t->funding_fee ?? 0);
            $window[] = $net > 0 ? 1 : 0;
            if (count($window) > 20) {
                array_shift($window);
            }
            if (count($window) >= 5) {
                $winRateHistory[] = round(array_sum($window) / count($window), 3);
            }
        }

        // Wrap the value() call before casting so a null result (no closed
        // trades yet) falls through to 0 instead of getting silently turned
        // into 0.0 by the cast and making `?? 0` dead code.
        $avgDuration = (float) (Trade::where('trades.is_dry_run', $isDryRun)
            ->whereNotNull('position_id')
            ->leftJoin('positions', 'positions.id', '=', 'trades.position_id')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, positions.opened_at, trades.created_at)) AS s')
            ->value('s') ?? 0);

        // position_size_usdt is already notional (margin × leverage at open
        // time). Open exposure = sum-notional / wallet × 100. Multiplying by
        // leverage again here would scale by leverage² — a long-standing bug
        // worth not repeating.
        $exposureUsdt = (float) Position::open()
            ->where('is_dry_run', $isDryRun)
            ->sum('position_size_usdt');
        $exposurePct = $walletBalance > 0
            ? round($exposureUsdt / $walletBalance * 100, 1)
            : 0.0;

        // Best / worst on net (pnl + funding_fee).
        $bestTrade = Trade::where('is_dry_run', $isDryRun)
            ->selectRaw("symbol, ($netExpr) AS net_pnl, created_at")
            ->orderByDesc('net_pnl')
            ->first();
        $worstTrade = Trade::where('is_dry_run', $isDryRun)
            ->selectRaw("symbol, ($netExpr) AS net_pnl, created_at")
            ->orderBy('net_pnl')
            ->first();

        // Streak walks most-recent trades and increments while the sign of
        // (pnl + funding_fee) matches.
        $streakRows = Trade::where('is_dry_run', $isDryRun)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['pnl', 'funding_fee']);
        $streakType = null;
        $streakCount = 0;
        foreach ($streakRows as $t) {
            $net = (float) $t->pnl + (float) ($t->funding_fee ?? 0);
            $isWin = $net > 0;
            $isLoss = $net < 0;
            if ($streakType === null) {
                if ($isWin) {
                    $streakType = 'win';
                    $streakCount = 1;
                } elseif ($isLoss) {
                    $streakType = 'loss';
                    $streakCount = 1;
                }
                continue;
            }
            if (($streakType === 'win' && $isWin) || ($streakType === 'loss' && $isLoss)) {
                $streakCount++;
            } else {
                break;
            }
        }

        $cooldownUntil = Cache::get('circuit_breaker:cooldown_until');
        $cbPeak = (float) (Cache::get('circuit_breaker:equity_peak') ?? 0.0);
        $cbActive = $cooldownUntil !== null && $cooldownUntil > $now->timestamp;

        // 30d funding split: paid (negative) vs received (positive).
        $fundingPaid = (float) Trade::where('is_dry_run', $isDryRun)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->where('funding_fee', '<', 0)
            ->sum('funding_fee');
        $fundingReceived = (float) Trade::where('is_dry_run', $isDryRun)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->where('funding_fee', '>', 0)
            ->sum('funding_fee');

        return response()->json([
            'is_dry_run' => $isDryRun,
            'equity' => round($equity, 2),
            'wallet_balance' => round($walletBalance, 2),
            'available_balance' => round($availableBalance, 2),
            'equity_24h_ago' => $equity24hAgo !== null ? round($equity24hAgo, 2) : null,
            'current_drawdown_pct' => round($currentDdPct, 2),
            'max_drawdown' => $maxDdPct > 0 ? [
                'pct' => round($maxDdPct, 2),
                'from' => $maxDdFrom?->toIso8601String(),
                'to' => $maxDdTo?->toIso8601String(),
            ] : null,
            'profit_factor_30d' => $profitFactor,
            'rolling_win_rate' => [
                'current' => $rollingWinRate,
                'history' => $winRateHistory,
                'window' => $recent20->count(),
            ],
            'avg_duration_seconds' => (int) round($avgDuration),
            'today_pnl' => round($todayPnl, 2),
            'exposure_pct' => $exposurePct,
            'exposure_usdt' => round($exposureUsdt, 2),
            'best_trade' => $bestTrade ? [
                'symbol' => $bestTrade->symbol,
                'pnl' => round((float) $bestTrade->net_pnl, 2),
                'date' => $bestTrade->created_at?->toIso8601String(),
            ] : null,
            'worst_trade' => $worstTrade ? [
                'symbol' => $worstTrade->symbol,
                'pnl' => round((float) $worstTrade->net_pnl, 2),
                'date' => $worstTrade->created_at?->toIso8601String(),
            ] : null,
            'current_streak' => [
                'type' => $streakType,
                'count' => $streakCount,
            ],
            'circuit_breaker' => [
                'enabled' => (bool) Settings::get('circuit_breaker_enabled'),
                'peak_equity' => round($cbPeak, 2),
                'is_active' => $cbActive,
                'cooldown_until' => $cbActive ? (int) $cooldownUntil : null,
            ],
            'funding_30d' => [
                'paid' => round($fundingPaid, 4),
                'received' => round($fundingReceived, 4),
                'net' => round($fundingPaid + $fundingReceived, 4),
            ],
            'ts' => $now->timestamp,
        ]);
    }

    public function tradeAggregates(Request $request): JsonResponse
    {
        $isDryRun = (bool) Settings::get('dry_run');
        $thirtyDaysAgo = now()->subDays(30);
        $netExpr = '(pnl + COALESCE(funding_fee, 0))';

        $bySymbol = Trade::where('is_dry_run', $isDryRun)
            ->selectRaw("symbol, SUM($netExpr) AS pnl, COUNT(*) AS trades")
            ->groupBy('symbol')
            ->orderByDesc('pnl')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'symbol' => $r->symbol,
                'pnl' => round((float) $r->pnl, 2),
                'trades' => (int) $r->trades,
            ])
            ->values();

        $byReason = Trade::where('is_dry_run', $isDryRun)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw("close_reason, COUNT(*) AS count, SUM($netExpr) AS pnl")
            ->groupBy('close_reason')
            ->get()
            ->map(fn ($r) => [
                'reason' => $r->close_reason instanceof CloseReason ? $r->close_reason->value : $r->close_reason,
                'count' => (int) $r->count,
                'pnl' => round((float) $r->pnl, 2),
            ])
            ->values();

        $byDayRows = Trade::where('is_dry_run', $isDryRun)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw("DATE(created_at) AS d, COUNT(*) AS trades, SUM($netExpr) AS pnl")
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        // Densify: emit an entry for every day in the window so the histogram
        // shows zero-trade days as gaps rather than skipping them.
        $byDay = [];
        $cursor = now()->subDays(29)->startOfDay();
        $end = now()->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format('Y-m-d');
            $row = $byDayRows->get($key);
            $byDay[] = [
                'date' => $key,
                'trades' => $row ? (int) $row->trades : 0,
                'pnl' => $row ? round((float) $row->pnl, 2) : 0.0,
            ];
            $cursor->addDay();
        }

        return response()->json([
            'is_dry_run' => $isDryRun,
            'by_symbol' => $bySymbol,
            'by_reason' => $byReason,
            'by_day' => $byDay,
            'ts' => now()->timestamp,
        ]);
    }
}
