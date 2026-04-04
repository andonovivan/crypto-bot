<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Models\Trade;
use App\Services\Exchange\ExchangeInterface;
use App\Services\Settings;
use App\Services\TradingEngine;
use App\Services\WaveScanner;
use App\Services\WaveSignal;
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

        // Refresh live prices for open positions (per-symbol, cached — 1 weight each vs 40 for getPrices)
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

        $recentTrades = Trade::with('position')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $totalPnl = Trade::sum('pnl');
        $totalTrades = Trade::count();
        $winningTrades = Trade::where('pnl', '>', 0)->count();
        $losingTrades = Trade::where('pnl', '<', 0)->count();
        $winRate = $totalTrades > 0 ? round(($winningTrades / $totalTrades) * 100, 1) : 0;

        $unrealizedPnl = $openPositions->sum('unrealized_pnl');
        $totalInvested = $openPositions->sum('position_size_usdt');
        $totalFees = Trade::sum('fees');

        $accountData = $exchange->getAccountData();

        // Estimate fees for open positions (entry + projected exit at current price)
        $feeRateCache = [];

        $positionsData = $openPositions->map(function (Position $p) use ($exchange, &$feeRateCache) {
            $currentPrice = $p->current_price ?? $p->entry_price;

            // Get taker fee rate (cached per symbol)
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
            $netPnl = round(($p->unrealized_pnl ?? 0) - $estimatedFees, 4);

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
                'net_pnl' => $netPnl,
                'best_price' => $p->best_price,
                'stop_loss_price' => $p->stop_loss_price,
                'take_profit_price' => $p->take_profit_price,
                'leverage' => $p->leverage,
                'layer_count' => $p->layer_count,
                'is_dry_run' => $p->is_dry_run,
                'opened_at' => $p->opened_at->timestamp,
                'expires_at' => $p->expires_at?->timestamp,
            ];
        });

        // Net P&L = realized trades (P&L minus fees) + open positions (unrealized minus estimated fees)
        $realizedNetPnl = $totalPnl - $totalFees;
        $openNetPnl = $positionsData->sum('net_pnl');
        $totalNetPnl = round($realizedNetPnl + $openNetPnl, 4);

        return response()->json([
            'positions' => $positionsData,
            'recent_trades' => $recentTrades->map(fn (Trade $t) => [
                'id' => $t->id,
                'symbol' => $t->symbol,
                'side' => $t->side,
                'entry_price' => $t->entry_price,
                'exit_price' => $t->exit_price,
                'quantity' => $t->quantity,
                'pnl' => $t->pnl,
                'pnl_pct' => $t->pnl_pct,
                'fees' => $t->fees,
                'close_reason' => $t->close_reason->value,
                'is_dry_run' => $t->is_dry_run,
                'position_size_usdt' => $t->position?->position_size_usdt,
                'leverage' => $t->position?->leverage,
                'opened_at' => $t->position?->opened_at?->timestamp,
                'created_at' => $t->created_at->timestamp,
            ]),
            'summary' => [
                'balance' => round($accountData['walletBalance'], 2),
                'wallet_balance' => round($accountData['walletBalance'], 2),
                'available_balance' => round($accountData['availableBalance'], 2),
                'margin_in_use' => round($accountData['positionMargin'], 2),
                'margin_balance' => round($accountData['marginBalance'], 2),
                'net_pnl' => $totalNetPnl,
                'total_fees' => round($totalFees, 4),
                'total_invested' => round($totalInvested, 2),
                'open_positions' => $openPositions->count(),
                'total_trades' => $totalTrades,
                'winning_trades' => $winningTrades,
                'losing_trades' => $losingTrades,
                'win_rate' => $winRate,
                'dry_run' => (bool) Settings::get('dry_run'),
            ],
            'ts' => now()->timestamp,
        ]);
    }

    public function scanNow(WaveScanner $waveScanner, TradingEngine $engine, Request $request): JsonResponse
    {
        $autoTrade = $request->boolean('auto_trade', false);
        $skipRsi = ! Settings::get('grid_rsi_filter');
        $trades = [];
        $signals = [];

        foreach ($waveScanner->getWatchlist() as $symbol) {
            $wave = $waveScanner->analyze($symbol, skipRsiFilter: $skipRsi);
            if ($wave) {
                $signals[] = [
                    'symbol' => $symbol,
                    'direction' => $wave->direction,
                    'state' => $wave->waveState,
                    'rsi' => $wave->rsi,
                ];

                $canEnter = in_array($wave->waveState, ['new_wave', 'riding']);

                // Cooldown check
                if ($autoTrade && $canEnter) {
                    $cooldown = (int) Settings::get('grid_cooldown_minutes') ?: 1;
                    if (Trade::where('symbol', $symbol)->where('created_at', '>=', now()->subMinutes($cooldown))->exists()) {
                        $canEnter = false;
                    }
                }

                // Per-symbol count check
                if ($autoTrade && $canEnter) {
                    $maxPerSymbol = (int) Settings::get('grid_max_per_symbol') ?: 10;
                    $symbolPositions = Position::open()->where('symbol', $symbol)->get();
                    if ($symbolPositions->count() >= $maxPerSymbol) {
                        $canEnter = false;
                    }

                    // Direction conflict: don't open if existing positions are in opposite direction
                    if ($canEnter && $symbolPositions->contains(fn ($p) => $p->side !== $wave->direction)) {
                        $canEnter = false;
                    }

                    // Grid spacing check
                    if ($canEnter) {
                        $spacingPct = (float) Settings::get('grid_spacing_pct') ?: 0.5;
                        foreach ($symbolPositions as $existing) {
                            $distancePct = abs($wave->currentPrice - $existing->entry_price) / $existing->entry_price * 100;
                            if ($distancePct < $spacingPct) {
                                $canEnter = false;
                                break;
                            }
                        }
                    }
                }

                if ($autoTrade && $canEnter) {
                    $signal = new WaveSignal(
                        symbol: $symbol,
                        direction: $wave->direction,
                        atr_value: $wave->atr,
                        currentPrice: $wave->currentPrice,
                        rsi: $wave->rsi,
                    );
                    $position = $engine->openPosition($signal, $wave->direction);
                    if ($position) {
                        $trades[] = $position->symbol;
                    }
                }
            }
        }

        return response()->json([
            'ok' => true,
            'signals' => $signals,
            'signal_count' => count($signals),
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
                    'layer_count' => $updated->layer_count,
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
}
