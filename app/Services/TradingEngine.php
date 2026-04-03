<?php

namespace App\Services;

use App\Enums\CloseReason;
use App\Enums\PositionStatus;
use App\Models\Position;
use App\Models\Trade;
use App\Services\Exchange\ExchangeInterface;
use Illuminate\Support\Facades\Log;

class TradingEngine
{
    public function __construct(
        private ExchangeInterface $exchange,
    ) {}

    /**
     * Open a position in the given direction based on a signal (WaveSignal DTO).
     */
    public function openPosition(object $signal, string $direction): ?Position
    {
        $maxPositions = Settings::get('max_positions');
        $leverage = (int) Settings::get('leverage');
        $isDryRun = (bool) Settings::get('dry_run');

        // Expiry
        $maxHoldMinutes = (int) Settings::get('grid_max_hold_minutes') ?: 1440;
        $expiresAt = now()->addMinutes($maxHoldMinutes);

        // Check if we've hit max total positions
        $openCount = Position::open()->count();
        if ($openCount >= $maxPositions) {
            Log::warning('Max positions reached, skipping', [
                'symbol' => $signal->symbol,
                'open_positions' => $openCount,
                'max' => $maxPositions,
            ]);
            return null;
        }

        // Grid: allow multiple positions per symbol up to max
        $maxPerSymbol = (int) Settings::get('grid_max_per_symbol') ?: 10;
        if (Position::open()->where('symbol', $signal->symbol)->count() >= $maxPerSymbol) {
            return null;
        }

        // Calculate position size dynamically from wallet balance
        // margin = walletBalance * (position_size_pct / 100)
        // notional = margin * leverage
        $accountData = $this->exchange->getAccountData();
        $positionSizePct = (float) Settings::get('position_size_pct') ?: 1.0;
        $margin = $accountData['walletBalance'] * ($positionSizePct / 100);
        $positionSizeUsdt = round($margin * max($leverage, 1), 2);

        // Check available margin before opening
        $requiredMargin = $positionSizeUsdt / max($leverage, 1);
        if ($accountData['availableBalance'] < $requiredMargin) {
            Log::warning('Insufficient available balance for new position', [
                'symbol' => $signal->symbol,
                'required_margin' => $requiredMargin,
                'available_balance' => $accountData['availableBalance'],
                'position_size_usdt' => $positionSizeUsdt,
            ]);
            return null;
        }

        // Verify the symbol is still tradable
        if (! $this->exchange->isTradable($signal->symbol)) {
            Log::warning('Symbol not tradable, skipping', ['symbol' => $signal->symbol]);
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

            // Calculate SL/TP — direction-specific fixed percentages
            $slTp = $this->calculateSlTp($entryPrice, $direction);

            // Place stop-loss and take-profit orders, storing their order IDs
            $slOrderId = null;
            $tpOrderId = null;
            try {
                $slResult = $this->exchange->setStopLoss($signal->symbol, $slTp['sl'], $order['quantity'], $direction);
                $slOrderId = $slResult['orderId'] ?? null;

                $tpResult = $this->exchange->setTakeProfit($signal->symbol, $slTp['tp'], $order['quantity'], $direction);
                $tpOrderId = $tpResult['orderId'] ?? null;
            } catch (\Throwable $e) {
                Log::error('Failed to set SL/TP orders — closing position for safety', [
                    'symbol' => $signal->symbol,
                    'error' => $e->getMessage(),
                ]);

                // Fail-safe: close the position immediately if SL/TP can't be placed
                try {
                    // Cancel whichever SL/TP was placed before the failure
                    if ($slOrderId) {
                        $this->exchange->cancelOrder($signal->symbol, $slOrderId);
                    }
                    if ($tpOrderId) {
                        $this->exchange->cancelOrder($signal->symbol, $tpOrderId);
                    }

                    // Close the position
                    $direction === 'LONG'
                        ? $this->exchange->closeLong($signal->symbol, $order['quantity'])
                        : $this->exchange->closeShort($signal->symbol, $order['quantity']);
                } catch (\Throwable $closeErr) {
                    Log::error('CRITICAL: Failed to close unprotected position', [
                        'symbol' => $signal->symbol,
                        'quantity' => $order['quantity'],
                        'error' => $closeErr->getMessage(),
                    ]);
                }

                return null;
            }

            $position = Position::create([
                'symbol' => $signal->symbol,
                'side' => $direction,
                'entry_price' => $entryPrice,
                'quantity' => $order['quantity'],
                'position_size_usdt' => $positionSizeUsdt,
                'stop_loss_price' => $slTp['sl'],
                'take_profit_price' => $slTp['tp'],
                'current_price' => $entryPrice,
                'best_price' => $entryPrice,
                'leverage' => $leverage,
                'layer_count' => 1,
                'atr_value' => $signal->atr_value ?? null,
                'status' => PositionStatus::Open,
                'exchange_order_id' => $order['orderId'],
                'sl_order_id' => $slOrderId,
                'tp_order_id' => $tpOrderId,
                'is_dry_run' => $isDryRun,
                'opened_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            Log::info('Position opened', [
                'symbol' => $signal->symbol,
                'side' => $direction,
                'entry_price' => $entryPrice,
                'quantity' => $order['quantity'],
                'stop_loss' => $slTp['sl'],
                'take_profit' => $slTp['tp'],
                'sl_order_id' => $slOrderId,
                'tp_order_id' => $tpOrderId,
                'leverage' => $leverage,
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
     * Check a single position and close if SL/TP/expiry hit.
     */
    public function checkPosition(Position $position, float $currentPrice): void
    {
        $isLong = $position->side === 'LONG';
        $unrealizedPnl = $this->calculatePnl($position, $currentPrice);

        // Track best price
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

        // Cancel this position's SL/TP orders by ID (safe for grid — doesn't nuke sibling positions)
        if ($position->sl_order_id || $position->tp_order_id) {
            if ($position->sl_order_id) {
                try {
                    $this->exchange->cancelOrder($position->symbol, $position->sl_order_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to cancel SL order on close', [
                        'symbol' => $position->symbol,
                        'sl_order_id' => $position->sl_order_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($position->tp_order_id) {
                try {
                    $this->exchange->cancelOrder($position->symbol, $position->tp_order_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to cancel TP order on close', [
                        'symbol' => $position->symbol,
                        'tp_order_id' => $position->tp_order_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } else {
            // Fallback for legacy positions without stored order IDs.
            // Uses cancelOrders (cancels ALL orders for symbol) — only safe when
            // this is the sole position on the symbol, or in dry-run mode.
            try {
                $this->exchange->cancelOrders($position->symbol);
            } catch (\Throwable $e) {
                Log::warning('Failed to cancel orders on close (legacy fallback)', [
                    'symbol' => $position->symbol,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Close the position on exchange using the correct direction
        $order = $position->side === 'LONG'
            ? $this->exchange->closeLong($position->symbol, $position->quantity)
            : $this->exchange->closeShort($position->symbol, $position->quantity);

        $actualExitPrice = $order['price'] > 0 ? $order['price'] : $exitPrice;

        $rawPnl = $this->calculatePnl($position, $actualExitPrice);
        $pnlPct = $this->calculatePnlPct($position, $actualExitPrice);

        // Calculate trading fees
        $rates = $this->exchange->getCommissionRate($position->symbol);
        $takerRate = $rates['taker'];
        $entryFee = $position->entry_price * $position->quantity * $takerRate;
        $exitFee = $actualExitPrice * $position->quantity * $takerRate;
        $totalFees = round($entryFee + $exitFee, 4);
        $pnl = round($rawPnl - $totalFees, 4);

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
            'fees' => $totalFees,
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
            'fees' => $totalFees,
            'pnl_pct' => round($pnlPct, 2) . '%',
        ]);

        return $trade;
    }

    /**
     * Calculate SL/TP prices. Direction-specific fixed percentages.
     *
     * @return array{sl: float, tp: float}
     */
    private function calculateSlTp(float $entryPrice, string $direction): array
    {
        $fallbackTp = (float) Settings::get('grid_take_profit_pct') ?: 1.68;
        $fallbackSl = (float) Settings::get('grid_stop_loss_pct') ?: 5.0;

        if ($direction === 'LONG') {
            $tpPct = (float) Settings::get('grid_long_tp_pct') ?: $fallbackTp;
            $slPct = (float) Settings::get('grid_long_sl_pct') ?: $fallbackSl;

            return [
                'sl' => round($entryPrice * (1 - $slPct / 100), 8),
                'tp' => round($entryPrice * (1 + $tpPct / 100), 8),
            ];
        }

        $tpPct = (float) Settings::get('grid_short_tp_pct') ?: $fallbackTp;
        $slPct = (float) Settings::get('grid_short_sl_pct') ?: $fallbackSl;

        return [
            'sl' => round($entryPrice * (1 + $slPct / 100), 8),
            'tp' => round($entryPrice * (1 - $tpPct / 100), 8),
        ];
    }

    /**
     * Calculate P&L for a position based on its direction.
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
