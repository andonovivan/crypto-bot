<?php

namespace App\Services;

use App\Enums\CloseReason;
use App\Enums\PositionStatus;
use App\Enums\SignalStatus;
use App\Models\Position;
use App\Models\PumpSignal;
use App\Models\Trade;
use App\Services\Exchange\ExchangeInterface;
use Illuminate\Support\Facades\Log;

class TradingEngine
{
    public function __construct(
        private ExchangeInterface $exchange,
    ) {}

    /**
     * Open a short position based on a confirmed pump signal.
     */
    public function openShort(PumpSignal $signal): ?Position
    {
        $maxPositions = Settings::get('max_positions');
        $leverage = (int) Settings::get('leverage');
        $positionSizeUsdt = (float) Settings::get('position_size_usdt');
        $stopLossPct = (float) Settings::get('stop_loss_pct');
        $takeProfitPct = (float) Settings::get('take_profit_pct');
        $maxHoldHours = (int) Settings::get('max_hold_hours');
        $isDryRun = (bool) Settings::get('dry_run');

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

            // Open short
            $order = $this->exchange->openShort($signal->symbol, $quantity);

            $entryPrice = $order['price'] > 0 ? $order['price'] : $price;
            $stopLossPrice = $entryPrice * (1 + $stopLossPct / 100);
            $takeProfitPrice = $entryPrice * (1 - $takeProfitPct / 100);

            // Place stop-loss and take-profit orders
            try {
                $this->exchange->setStopLoss($signal->symbol, $stopLossPrice, $quantity);
                $this->exchange->setTakeProfit($signal->symbol, $takeProfitPrice, $quantity);
            } catch (\Throwable $e) {
                Log::warning('Failed to set SL/TP orders', [
                    'symbol' => $signal->symbol,
                    'error' => $e->getMessage(),
                ]);
            }

            $position = Position::create([
                'pump_signal_id' => $signal->id,
                'symbol' => $signal->symbol,
                'side' => 'SHORT',
                'entry_price' => $entryPrice,
                'quantity' => $order['quantity'],
                'position_size_usdt' => $positionSizeUsdt,
                'stop_loss_price' => $stopLossPrice,
                'take_profit_price' => $takeProfitPrice,
                'current_price' => $entryPrice,
                'leverage' => $leverage,
                'status' => PositionStatus::Open,
                'exchange_order_id' => $order['orderId'],
                'is_dry_run' => $isDryRun,
                'opened_at' => now(),
                'expires_at' => now()->addHours($maxHoldHours),
            ]);

            $signal->update(['status' => SignalStatus::Traded]);

            Log::info('Short position opened', [
                'symbol' => $signal->symbol,
                'entry_price' => $entryPrice,
                'quantity' => $order['quantity'],
                'stop_loss' => $stopLossPrice,
                'take_profit' => $takeProfitPrice,
                'leverage' => $leverage,
                'dry_run' => $isDryRun,
            ]);

            return $position;

        } catch (\Throwable $e) {
            Log::error('Failed to open short', [
                'symbol' => $signal->symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Monitor all open positions and close if conditions are met.
     */
    public function monitorPositions(): void
    {
        $positions = Position::open()->get();

        foreach ($positions as $position) {
            try {
                $this->checkPosition($position);
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
     */
    private function checkPosition(Position $position): void
    {
        $currentPrice = $this->exchange->getPrice($position->symbol);
        $unrealizedPnl = $this->calculatePnl($position, $currentPrice);

        $position->update([
            'current_price' => $currentPrice,
            'unrealized_pnl' => $unrealizedPnl,
        ]);

        // Check stop-loss (price went up for a short)
        if ($position->stop_loss_price && $currentPrice >= $position->stop_loss_price) {
            $this->closePosition($position, $currentPrice, CloseReason::StopLoss);
            return;
        }

        // Check take-profit (price went down for a short)
        if ($position->take_profit_price && $currentPrice <= $position->take_profit_price) {
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
     * Close a position.
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

        // Close the position on exchange
        $order = $this->exchange->closeShort($position->symbol, $position->quantity);
        $actualExitPrice = $order['price'] > 0 ? $order['price'] : $exitPrice;

        $pnl = $this->calculatePnl($position, $actualExitPrice);
        $pnlPct = $position->entry_price > 0
            ? (($position->entry_price - $actualExitPrice) / $position->entry_price) * 100
            : 0;

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
            'side' => 'SHORT',
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
            'reason' => $reason->value,
            'entry' => $position->entry_price,
            'exit' => $actualExitPrice,
            'pnl' => $pnl,
            'pnl_pct' => round($pnlPct, 2) . '%',
        ]);

        return $trade;
    }

    /**
     * Calculate P&L for a short position.
     * Short P&L = (entry - current) * quantity
     */
    private function calculatePnl(Position $position, float $currentPrice): float
    {
        return round(($position->entry_price - $currentPrice) * $position->quantity, 4);
    }
}
