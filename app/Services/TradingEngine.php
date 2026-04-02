<?php

namespace App\Services;

use App\Enums\CloseReason;
use App\Enums\PositionStatus;
use App\Enums\SignalStatus;
use App\Models\Position;
use App\Models\PumpSignal;
use App\Models\TrendSignal;
use App\Models\Trade;
use App\Services\Exchange\ExchangeInterface;
use Illuminate\Support\Facades\Log;

class TradingEngine
{
    public function __construct(
        private ExchangeInterface $exchange,
    ) {}

    /**
     * Open a position in the given direction based on any signal type.
     */
    public function openPosition(object $signal, string $direction, string $settingsPrefix = ''): ?Position
    {
        $maxPositions = Settings::get('max_positions');
        $leverage = (int) Settings::get('leverage');
        $positionSizeUsdt = (float) Settings::get('position_size_usdt');
        $isDryRun = (bool) Settings::get('dry_run');

        // Use strategy-specific settings if prefix provided, else global
        $stopLossPct = (float) Settings::get($settingsPrefix . 'stop_loss_pct');
        $takeProfitPct = (float) Settings::get($settingsPrefix . 'take_profit_pct');
        $maxHoldHours = (int) Settings::get($settingsPrefix . 'max_hold_hours');

        // Check if we've hit max positions
        $openCount = Position::open()->count();
        if ($openCount >= $maxPositions) {
            Log::warning('Max positions reached, skipping', [
                'symbol' => $signal->symbol,
                'open_positions' => $openCount,
                'max' => $maxPositions,
            ]);
            return null;
        }

        // Check if we already have an open position for this symbol
        if (Position::open()->where('symbol', $signal->symbol)->exists()) {
            Log::info('Already have open position for symbol', ['symbol' => $signal->symbol]);
            return null;
        }

        // Verify the symbol is still tradable
        if (! $this->exchange->isTradable($signal->symbol)) {
            Log::warning('Symbol not tradable, skipping', ['symbol' => $signal->symbol]);
            $signal->update(['status' => SignalStatus::Skipped]);
            return null;
        }

        try {
            $price = $this->exchange->getPrice($signal->symbol);

            // Set leverage
            $this->exchange->setLeverage($signal->symbol, $leverage);

            // Calculate quantity
            $quantity = $this->exchange->calculateQuantity($signal->symbol, $positionSizeUsdt, $price);

            if ($quantity <= 0) {
                Log::warning('Calculated quantity is zero', ['symbol' => $signal->symbol, 'price' => $price]);
                return null;
            }

            // Open position in the correct direction
            $order = $direction === 'LONG'
                ? $this->exchange->openLong($signal->symbol, $quantity)
                : $this->exchange->openShort($signal->symbol, $quantity);

            $entryPrice = $order['price'] > 0 ? $order['price'] : $price;

            // Calculate SL/TP based on direction
            if ($direction === 'LONG') {
                $stopLossPrice = $entryPrice * (1 - $stopLossPct / 100);
                $takeProfitPrice = $entryPrice * (1 + $takeProfitPct / 100);
            } else {
                $stopLossPrice = $entryPrice * (1 + $stopLossPct / 100);
                $takeProfitPrice = $entryPrice * (1 - $takeProfitPct / 100);
            }

            // Place stop-loss and take-profit orders
            try {
                $this->exchange->setStopLoss($signal->symbol, $stopLossPrice, $quantity, $direction);
                $this->exchange->setTakeProfit($signal->symbol, $takeProfitPrice, $quantity, $direction);
            } catch (\Throwable $e) {
                Log::warning('Failed to set SL/TP orders', [
                    'symbol' => $signal->symbol,
                    'error' => $e->getMessage(),
                ]);
            }

            // Determine which signal FK to set
            $signalFk = $signal instanceof PumpSignal
                ? ['pump_signal_id' => $signal->id]
                : ['trend_signal_id' => $signal->id];

            $position = Position::create(array_merge($signalFk, [
                'symbol' => $signal->symbol,
                'side' => $direction,
                'entry_price' => $entryPrice,
                'quantity' => $order['quantity'],
                'position_size_usdt' => $positionSizeUsdt,
                'stop_loss_price' => $stopLossPrice,
                'take_profit_price' => $takeProfitPrice,
                'current_price' => $entryPrice,
                'best_price' => $entryPrice,
                'leverage' => $leverage,
                'status' => PositionStatus::Open,
                'exchange_order_id' => $order['orderId'],
                'is_dry_run' => $isDryRun,
                'opened_at' => now(),
                'expires_at' => now()->addHours($maxHoldHours),
            ]));

            $signal->update(['status' => SignalStatus::Traded]);

            Log::info('Position opened', [
                'symbol' => $signal->symbol,
                'side' => $direction,
                'entry_price' => $entryPrice,
                'quantity' => $order['quantity'],
                'stop_loss' => $stopLossPrice,
                'take_profit' => $takeProfitPrice,
                'leverage' => $leverage,
                'dry_run' => $isDryRun,
            ]);

            return $position;

        } catch (\Throwable $e) {
            Log::error('Failed to open position', [
                'symbol' => $signal->symbol,
                'side' => $direction,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Open a short position based on a confirmed pump signal.
     * Backward-compatible wrapper around openPosition().
     */
    public function openShort(PumpSignal $signal): ?Position
    {
        return $this->openPosition($signal, 'SHORT');
    }

    /**
     * Retry trading on reversal_confirmed signals that don't have active positions.
     * This catches cases where a previous position was stopped out or closed,
     * but the coin is still in a confirmed reversal state.
     *
     * Skips symbols that had a stop-loss within the last 24 hours to avoid
     * compounding losses on the same pump.
     *
     * @return array<int, Position> Positions opened
     */
    public function retryConfirmedSignals(): array
    {
        $openedPositions = [];

        $confirmedSignals = PumpSignal::where('status', SignalStatus::ReversalConfirmed)
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

        // Get symbols that were stopped out within the cooldown window — skip these
        $cooldownHours = (int) Settings::get('retry_cooldown_hours');
        $recentStopLossSymbols = Trade::where('close_reason', CloseReason::StopLoss)
            ->where('created_at', '>=', now()->subHours($cooldownHours))
            ->pluck('symbol')
            ->unique()
            ->toArray();

        foreach ($confirmedSignals as $signal) {
            if (in_array($signal->symbol, $recentStopLossSymbols)) {
                Log::info('Skipping retry — recent stop loss cooldown', ['symbol' => $signal->symbol]);
                continue;
            }

            $position = $this->openShort($signal);
            if ($position) {
                $openedPositions[] = $position;
            }
        }

        return $openedPositions;
    }

    /**
     * Monitor all open positions and close if conditions are met.
     * Uses batch price fetching for efficiency.
     */
    public function monitorPositions(): void
    {
        $positions = Position::open()->get();

        if ($positions->isEmpty()) {
            return;
        }

        // Fetch all prices in one API call
        $symbols = $positions->pluck('symbol')->unique()->toArray();
        $prices = $this->exchange->getPrices($symbols);

        foreach ($positions as $position) {
            try {
                $currentPrice = $prices[$position->symbol] ?? null;

                if ($currentPrice === null) {
                    Log::warning('No price data for position', ['symbol' => $position->symbol]);
                    continue;
                }

                $this->checkPosition($position, $currentPrice);
            } catch (\Throwable $e) {
                Log::warning('Failed to monitor position', [
                    'position_id' => $position->id,
                    'symbol' => $position->symbol,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Check a single position and close if SL/TP/expiry hit.
     * Applies trailing stop when position is profitable enough.
     * Supports both LONG and SHORT positions.
     */
    private function checkPosition(Position $position, float $currentPrice): void
    {
        $isLong = $position->side === 'LONG';
        $unrealizedPnl = $this->calculatePnl($position, $currentPrice);

        // Track best price: highest for LONG (most profitable), lowest for SHORT
        $bestPrice = $position->best_price ?? $position->entry_price;
        if ($isLong) {
            if ($currentPrice > $bestPrice) {
                $bestPrice = $currentPrice;
            }
        } else {
            if ($currentPrice < $bestPrice) {
                $bestPrice = $currentPrice;
            }
        }

        $position->update([
            'current_price' => $currentPrice,
            'best_price' => $bestPrice,
            'unrealized_pnl' => $unrealizedPnl,
        ]);

        // Trailing stop: once profit exceeds activation threshold, tighten stop loss
        $settingsPrefix = $position->trend_signal_id ? 'trend_' : '';
        $trailingActivationPct = (float) Settings::get($settingsPrefix . 'trailing_stop_activation_pct');
        $trailingStopPct = (float) Settings::get($settingsPrefix . 'trailing_stop_pct');

        if ($trailingActivationPct > 0 && $trailingStopPct > 0 && $position->entry_price > 0) {
            $profitPct = $isLong
                ? (($bestPrice - $position->entry_price) / $position->entry_price) * 100
                : (($position->entry_price - $bestPrice) / $position->entry_price) * 100;

            if ($profitPct >= $trailingActivationPct) {
                $trailingStopPrice = $isLong
                    ? $bestPrice * (1 - $trailingStopPct / 100)  // Below best for LONG
                    : $bestPrice * (1 + $trailingStopPct / 100); // Above best for SHORT

                // Only tighten — never loosen the stop loss
                $shouldUpdate = $isLong
                    ? $trailingStopPrice > $position->stop_loss_price
                    : $trailingStopPrice < $position->stop_loss_price;

                if ($shouldUpdate) {
                    $position->update(['stop_loss_price' => $trailingStopPrice]);

                    Log::info('Trailing stop updated', [
                        'symbol' => $position->symbol,
                        'side' => $position->side,
                        'best_price' => $bestPrice,
                        'new_stop_loss' => $trailingStopPrice,
                        'profit_pct' => round($profitPct, 2),
                    ]);
                }
            }
        }

        // Check stop-loss
        $slHit = $isLong
            ? ($position->stop_loss_price && $currentPrice <= $position->stop_loss_price)
            : ($position->stop_loss_price && $currentPrice >= $position->stop_loss_price);

        if ($slHit) {
            $this->closePosition($position, $currentPrice, CloseReason::StopLoss);
            return;
        }

        // Check take-profit
        $tpHit = $isLong
            ? ($position->take_profit_price && $currentPrice >= $position->take_profit_price)
            : ($position->take_profit_price && $currentPrice <= $position->take_profit_price);

        if ($tpHit) {
            $this->closePosition($position, $currentPrice, CloseReason::TakeProfit);
            return;
        }

        // Check time expiry
        if ($position->expires_at && now()->gte($position->expires_at)) {
            $this->closePosition($position, $currentPrice, CloseReason::Expired);
            return;
        }
    }

    /**
     * Close a position. Supports both LONG and SHORT.
     */
    public function closePosition(Position $position, ?float $exitPrice = null, CloseReason $reason = CloseReason::Manual): Trade
    {
        if ($exitPrice === null) {
            $exitPrice = $this->exchange->getPrice($position->symbol);
        }

        // Cancel any open SL/TP orders
        try {
            $this->exchange->cancelOrders($position->symbol);
        } catch (\Throwable $e) {
            Log::warning('Failed to cancel orders on close', ['error' => $e->getMessage()]);
        }

        // Close the position on exchange using the correct direction
        $order = $position->side === 'LONG'
            ? $this->exchange->closeLong($position->symbol, $position->quantity)
            : $this->exchange->closeShort($position->symbol, $position->quantity);

        $actualExitPrice = $order['price'] > 0 ? $order['price'] : $exitPrice;

        $pnl = $this->calculatePnl($position, $actualExitPrice);
        $pnlPct = $this->calculatePnlPct($position, $actualExitPrice);

        $status = match ($reason) {
            CloseReason::StopLoss => PositionStatus::StoppedOut,
            CloseReason::Expired => PositionStatus::Expired,
            default => PositionStatus::Closed,
        };

        $position->update([
            'current_price' => $actualExitPrice,
            'unrealized_pnl' => 0,
            'status' => $status,
        ]);

        $trade = Trade::create([
            'position_id' => $position->id,
            'symbol' => $position->symbol,
            'side' => $position->side,
            'type' => 'close',
            'entry_price' => $position->entry_price,
            'exit_price' => $actualExitPrice,
            'quantity' => $position->quantity,
            'pnl' => $pnl,
            'pnl_pct' => round($pnlPct, 4),
            'close_reason' => $reason,
            'exchange_order_id' => $order['orderId'],
            'is_dry_run' => $position->is_dry_run,
        ]);

        Log::info('Position closed', [
            'symbol' => $position->symbol,
            'side' => $position->side,
            'reason' => $reason->value,
            'entry' => $position->entry_price,
            'exit' => $actualExitPrice,
            'pnl' => $pnl,
            'pnl_pct' => round($pnlPct, 2) . '%',
        ]);

        return $trade;
    }

    /**
     * Calculate P&L for a position based on its direction.
     * LONG: (current - entry) * quantity
     * SHORT: (entry - current) * quantity
     */
    private function calculatePnl(Position $position, float $currentPrice): float
    {
        if ($position->side === 'LONG') {
            return round(($currentPrice - $position->entry_price) * $position->quantity, 4);
        }

        return round(($position->entry_price - $currentPrice) * $position->quantity, 4);
    }

    /**
     * Calculate P&L percentage for a position based on its direction.
     */
    private function calculatePnlPct(Position $position, float $currentPrice): float
    {
        if ($position->entry_price <= 0) {
            return 0;
        }

        if ($position->side === 'LONG') {
            return (($currentPrice - $position->entry_price) / $position->entry_price) * 100;
        }

        return (($position->entry_price - $currentPrice) / $position->entry_price) * 100;
    }
}
