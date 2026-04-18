<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Models\Trade;
use App\Services\Exchange\ExchangeInterface;
use App\Services\Settings;
use App\Services\ShortScanner;
use App\Services\ShortSignal;
use App\Services\TradingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        return view('dashboard');
    }

    public function data(ExchangeInterface $exchange): JsonResponse
    {
        $openPositions = Position::open()
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

        $totalPnl = Trade::sum('pnl');
        $totalTrades = Trade::count();
        $winningTrades = Trade::where('pnl', '>', 0)->count();
        $losingTrades = Trade::where('pnl', '<', 0)->count();
        $winRate = $totalTrades > 0 ? round(($winningTrades / $totalTrades) * 100, 1) : 0;

        $totalInvested = $openPositions->sum('position_size_usdt');
        $totalFees = Trade::sum('fees');

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

        $closedFunding = Trade::sum('funding_fee');
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
                'dry_run' => (bool) Settings::get('dry_run'),
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
                'position_size_usdt' => $t->position?->position_size_usdt,
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

    public function scanNow(ShortScanner $scanner, TradingEngine $engine, Request $request): JsonResponse
    {
        $autoTrade = $request->boolean('auto_trade', false);
        $cooldown = (int) Settings::get('cooldown_minutes') ?: 120;
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

    public function settings(): JsonResponse
    {
        return response()->json([
            'settings' => Settings::all(),
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
}
