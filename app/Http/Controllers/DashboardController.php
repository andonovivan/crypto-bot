<?php

namespace App\Http\Controllers;

use App\Enums\CloseReason;
use App\Models\Position;
use App\Models\PumpSignal;
use App\Models\TrendSignal;
use App\Models\Trade;
use App\Services\Exchange\ExchangeInterface;
use App\Services\PumpScanner;
use App\Services\Settings;
use App\Services\TradingEngine;
use App\Services\TrendScanner;
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
            ->with(['pumpSignal', 'trendSignal'])
            ->orderByDesc('opened_at')
            ->get();

        // Refresh live prices for open positions (single batch API call)
        if ($openPositions->isNotEmpty()) {
            try {
                $symbols = $openPositions->pluck('symbol')->unique()->toArray();
                $prices = $exchange->getPrices($symbols);

                foreach ($openPositions as $position) {
                    $currentPrice = $prices[$position->symbol] ?? null;
                    if ($currentPrice !== null) {
                        $isLong = $position->side === 'LONG';
                        $pnl = $isLong
                            ? round(($currentPrice - $position->entry_price) * $position->quantity, 4)
                            : round(($position->entry_price - $currentPrice) * $position->quantity, 4);
                        $position->update([
                            'current_price' => $currentPrice,
                            'unrealized_pnl' => $pnl,
                        ]);
                    }
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
        $strategy = (string) Settings::get('strategy') ?: 'trend';

        // Active signals: both pump and trend
        $pumpSignals = PumpSignal::whereIn('status', ['detected', 'reversal_confirmed'])
            ->where('created_at', '>=', now()->subHours(24))
            ->orderByDesc('price_change_pct')
            ->get();

        $trendSignals = TrendSignal::whereIn('status', ['detected'])
            ->where('created_at', '>=', now()->subHours(4))
            ->orderByDesc('score')
            ->get();

        return response()->json([
            'positions' => $openPositions->map(fn (Position $p) => [
                'id' => $p->id,
                'symbol' => $p->symbol,
                'side' => $p->side,
                'entry_price' => $p->entry_price,
                'current_price' => $p->current_price,
                'quantity' => $p->quantity,
                'position_size_usdt' => $p->position_size_usdt,
                'unrealized_pnl' => $p->unrealized_pnl,
                'pnl_pct' => $p->entry_price > 0
                    ? round($p->side === 'LONG'
                        ? ((($p->current_price ?? $p->entry_price) - $p->entry_price) / $p->entry_price) * 100
                        : (($p->entry_price - ($p->current_price ?? $p->entry_price)) / $p->entry_price) * 100, 2)
                    : 0,
                'best_price' => $p->best_price,
                'stop_loss_price' => $p->stop_loss_price,
                'take_profit_price' => $p->take_profit_price,
                'leverage' => $p->leverage,
                'is_dry_run' => $p->is_dry_run,
                'opened_at' => $p->opened_at->timestamp,
                'expires_at' => $p->expires_at?->timestamp,
            ]),
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
                'created_at' => $t->created_at->timestamp,
            ]),
            'pump_signals' => $pumpSignals->map(fn (PumpSignal $s) => [
                'id' => $s->id,
                'symbol' => $s->symbol,
                'price_change_pct' => $s->price_change_pct,
                'volume_multiplier' => $s->volume_multiplier,
                'drop_from_peak_pct' => $s->drop_from_peak_pct,
                'status' => $s->status->value,
                'current_price' => $s->current_price,
                'peak_price' => $s->peak_price,
                'created_at' => $s->created_at->timestamp,
            ]),
            'trend_signals' => $trendSignals->map(fn (TrendSignal $s) => [
                'id' => $s->id,
                'symbol' => $s->symbol,
                'direction' => $s->direction,
                'score' => $s->score,
                'ema_cross' => $s->ema_cross,
                'rsi_value' => $s->rsi_value,
                'macd_histogram' => $s->macd_histogram,
                'volume_ratio' => $s->volume_ratio,
                'status' => $s->status->value,
                'entry_price' => $s->entry_price,
                'created_at' => $s->created_at->timestamp,
            ]),
            // Backward compat: 'signals' key returns whichever strategy is active
            'signals' => $strategy === 'trend'
                ? $trendSignals->map(fn (TrendSignal $s) => [
                    'id' => $s->id,
                    'symbol' => $s->symbol,
                    'direction' => $s->direction,
                    'score' => $s->score,
                    'status' => $s->status->value,
                    'entry_price' => $s->entry_price,
                    'created_at' => $s->created_at->timestamp,
                ])
                : $pumpSignals->map(fn (PumpSignal $s) => [
                    'id' => $s->id,
                    'symbol' => $s->symbol,
                    'price_change_pct' => $s->price_change_pct,
                    'volume_multiplier' => $s->volume_multiplier,
                    'drop_from_peak_pct' => $s->drop_from_peak_pct,
                    'status' => $s->status->value,
                    'current_price' => $s->current_price,
                    'peak_price' => $s->peak_price,
                    'created_at' => $s->created_at->timestamp,
                ]),
            'summary' => [
                'balance' => round($accountData['walletBalance'], 2),
                'wallet_balance' => round($accountData['walletBalance'], 2),
                'available_balance' => round($accountData['availableBalance'], 2),
                'margin_in_use' => round($accountData['positionMargin'], 2),
                'margin_balance' => round($accountData['marginBalance'], 2),
                'combined_pnl' => round($totalPnl + $unrealizedPnl, 4),
                'realized_pnl' => round($totalPnl, 4),
                'unrealized_pnl' => round($unrealizedPnl, 4),
                'total_fees' => round($totalFees, 4),
                'total_invested' => round($totalInvested, 2),
                'open_positions' => $openPositions->count(),
                'total_trades' => $totalTrades,
                'winning_trades' => $winningTrades,
                'losing_trades' => $losingTrades,
                'win_rate' => $winRate,
                'active_signals' => $strategy === 'trend' ? $trendSignals->count() : $pumpSignals->count(),
                'dry_run' => (bool) Settings::get('dry_run'),
                'strategy' => $strategy,
            ],
            'ts' => now()->timestamp,
        ]);
    }

    public function scanNow(PumpScanner $pumpScanner, TrendScanner $trendScanner, TradingEngine $engine, Request $request): JsonResponse
    {
        $autoTrade = $request->boolean('auto_trade', false);
        $strategy = (string) Settings::get('strategy') ?: 'trend';

        if ($strategy === 'trend') {
            $signals = $trendScanner->scan();
            $expired = $trendScanner->expireStaleSignals();

            $trades = [];
            if ($autoTrade) {
                foreach ($signals as $signal) {
                    if ($signal->status->value !== 'detected') {
                        continue;
                    }
                    $position = $engine->openPosition($signal, $signal->direction, 'trend_');
                    if ($position) {
                        $trades[] = $position->symbol;
                    }
                }
            }

            return response()->json([
                'ok' => true,
                'strategy' => 'trend',
                'signals' => $signals->count(),
                'expired' => $expired,
                'trades_opened' => $trades,
            ]);
        }

        // Pump strategy
        $signals = $pumpScanner->scan();
        $reversals = $pumpScanner->checkReversals();
        $expired = $pumpScanner->expireStaleSignals();

        $trades = [];
        if ($autoTrade) {
            foreach ($reversals as $signal) {
                $position = $engine->openShort($signal);
                if ($position) {
                    $trades[] = $position->symbol;
                }
            }

            foreach ($engine->retryConfirmedSignals() as $position) {
                $trades[] = $position->symbol;
            }
        }

        return response()->json([
            'ok' => true,
            'strategy' => 'pump',
            'signals' => $signals->count(),
            'reversals' => $reversals->count(),
            'expired' => $expired,
            'trades_opened' => $trades,
        ]);
    }

    public function closePosition(Request $request, TradingEngine $engine): JsonResponse
    {
        $request->validate(['position_id' => 'required|integer']);

        $position = Position::open()->findOrFail($request->position_id);
        $trade = $engine->closePosition($position, reason: CloseReason::Manual);

        return response()->json([
            'ok' => true,
            'pnl' => $trade->pnl,
            'exit_price' => $trade->exit_price,
        ]);
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
        PumpSignal::truncate();
        TrendSignal::truncate();
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
