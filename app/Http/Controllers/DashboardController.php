<?php

namespace App\Http\Controllers;

use App\Enums\CloseReason;
use App\Models\Position;
use App\Models\PumpSignal;
use App\Models\Trade;
use App\Services\Exchange\ExchangeInterface;
use App\Services\PumpScanner;
use App\Services\Settings;
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
            ->with('pumpSignal')
            ->orderByDesc('opened_at')
            ->get();

        // Refresh live prices for open positions
        foreach ($openPositions as $position) {
            try {
                $currentPrice = $exchange->getPrice($position->symbol);
                $pnl = round(($position->entry_price - $currentPrice) * $position->quantity, 4);
                $position->update([
                    'current_price' => $currentPrice,
                    'unrealized_pnl' => $pnl,
                ]);
            } catch (\Throwable $e) {
                // Use last known price if API fails
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

        $activeSignals = PumpSignal::whereIn('status', ['detected', 'reversal_confirmed'])
            ->where('created_at', '>=', now()->subHours(24))
            ->orderByDesc('price_change_pct')
            ->get();

        return response()->json([
            'positions' => $openPositions->map(fn (Position $p) => [
                'id' => $p->id,
                'symbol' => $p->symbol,
                'entry_price' => $p->entry_price,
                'current_price' => $p->current_price,
                'quantity' => $p->quantity,
                'position_size_usdt' => $p->position_size_usdt,
                'unrealized_pnl' => $p->unrealized_pnl,
                'pnl_pct' => $p->entry_price > 0
                    ? round((($p->entry_price - ($p->current_price ?? $p->entry_price)) / $p->entry_price) * 100, 2)
                    : 0,
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
                'entry_price' => $t->entry_price,
                'exit_price' => $t->exit_price,
                'quantity' => $t->quantity,
                'pnl' => $t->pnl,
                'pnl_pct' => $t->pnl_pct,
                'close_reason' => $t->close_reason->value,
                'is_dry_run' => $t->is_dry_run,
                'created_at' => $t->created_at->timestamp,
            ]),
            'signals' => $activeSignals->map(fn (PumpSignal $s) => [
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
                'balance' => round($exchange->getBalance(), 2),
                'combined_pnl' => round($totalPnl + $unrealizedPnl, 4),
                'realized_pnl' => round($totalPnl, 4),
                'unrealized_pnl' => round($unrealizedPnl, 4),
                'total_invested' => round($totalInvested, 2),
                'open_positions' => $openPositions->count(),
                'total_trades' => $totalTrades,
                'winning_trades' => $winningTrades,
                'losing_trades' => $losingTrades,
                'win_rate' => $winRate,
                'active_signals' => $activeSignals->count(),
                'dry_run' => (bool) Settings::get('dry_run'),
            ],
            'ts' => now()->timestamp,
        ]);
    }

    public function scanNow(PumpScanner $scanner, TradingEngine $engine, Request $request): JsonResponse
    {
        $autoTrade = $request->boolean('auto_trade', false);

        $signals = $scanner->scan();
        $reversals = $scanner->checkReversals();
        $expired = $scanner->expireStaleSignals();

        $trades = [];
        if ($autoTrade) {
            foreach ($reversals as $signal) {
                $position = $engine->openShort($signal);
                if ($position) {
                    $trades[] = $position->symbol;
                }
            }
        }

        return response()->json([
            'ok' => true,
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
        Trade::truncate();
        Position::truncate();
        PumpSignal::truncate();

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
