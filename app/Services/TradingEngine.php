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

            // Place SL/TP orders via helper — fail-safe: close position if SL/TP can't be placed
            $slTpResult = null;
            try {
                // Create a temporary position-like object for replaceSlTpOrders
                $tempPosition = new Position([
                    'symbol' => $signal->symbol,
                    'side' => $direction,
                    'sl_order_id' => null,
                    'tp_order_id' => null,
                ]);
                $slTpResult = $this->replaceSlTpOrders($tempPosition, $entryPrice, $order['quantity']);
            } catch (\Throwable $e) {
                Log::error('Failed to set SL/TP orders — closing position for safety', [
                    'symbol' => $signal->symbol,
                    'error' => $e->getMessage(),
                ]);

                // Fail-safe: close the position immediately
                try {
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

            // Calculate initial entry fee for accurate P&L tracking
            $rates = $this->exchange->getCommissionRate($signal->symbol);
            $initialEntryFee = round($entryPrice * $order['quantity'] * $rates['taker'], 8);

            $position = Position::create([
                'symbol' => $signal->symbol,
                'side' => $direction,
                'entry_price' => $entryPrice,
                'quantity' => $order['quantity'],
                'position_size_usdt' => $positionSizeUsdt,
                'stop_loss_price' => $slTpResult['sl'],
                'take_profit_price' => $slTpResult['tp'],
                'current_price' => $entryPrice,
                'best_price' => $entryPrice,
                'leverage' => $leverage,
                'layer_count' => 1,
                'atr_value' => $signal->atr_value ?? null,
                'status' => PositionStatus::Open,
                'exchange_order_id' => $order['orderId'],
                'sl_order_id' => $slTpResult['sl_order_id'],
                'tp_order_id' => $slTpResult['tp_order_id'],
                'total_entry_fee' => $initialEntryFee,
                'is_dry_run' => $isDryRun,
                'opened_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            Log::info('Position opened', [
                'symbol' => $signal->symbol,
                'side' => $direction,
                'entry_price' => $entryPrice,
                'quantity' => $order['quantity'],
                'stop_loss' => $slTpResult['sl'],
                'take_profit' => $slTpResult['tp'],
                'sl_order_id' => $slTpResult['sl_order_id'],
                'tp_order_id' => $slTpResult['tp_order_id'],
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

        // Calculate trading fees (use tracked entry fee if available for multi-add accuracy)
        $rates = $this->exchange->getCommissionRate($position->symbol);
        $takerRate = $rates['taker'];
        $entryFee = $position->total_entry_fee
            ?? ($position->entry_price * $position->quantity * $takerRate);
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
     * Add margin/quantity to an existing open position.
     * Places additional market order, recalculates weighted average entry, replaces SL/TP.
     */
    public function addToPosition(Position $position, float $additionalUsdt): Position
    {
        if ($position->status !== PositionStatus::Open) {
            throw new \RuntimeException('Cannot add to a position that is not open');
        }

        if ($additionalUsdt <= 0) {
            throw new \RuntimeException('Additional amount must be greater than zero');
        }

        // Check available margin
        $leverage = $position->leverage > 0 ? $position->leverage : 1;
        $requiredMargin = $additionalUsdt / $leverage;
        $accountData = $this->exchange->getAccountData();

        if ($accountData['availableBalance'] < $requiredMargin) {
            throw new \RuntimeException(
                "Insufficient balance: need \${$requiredMargin} margin, have \${$accountData['availableBalance']} available"
            );
        }

        // Get current price and calculate additional quantity
        $currentPrice = $this->exchange->getPrice($position->symbol);
        $additionalQty = $this->exchange->calculateQuantity($position->symbol, $additionalUsdt, $currentPrice);

        if ($additionalQty <= 0) {
            throw new \RuntimeException('Amount too small — calculated quantity is zero for this symbol');
        }

        // Place additional market order in the same direction
        $order = $position->side === 'LONG'
            ? $this->exchange->openLong($position->symbol, $additionalQty)
            : $this->exchange->openShort($position->symbol, $additionalQty);

        $fillPrice = $order['price'] > 0 ? $order['price'] : $currentPrice;
        $fillQty = $order['quantity'];

        // Calculate weighted average entry price
        $oldQty = $position->quantity;
        $oldEntry = $position->entry_price;
        $totalQty = $oldQty + $fillQty;
        $newAvgEntry = round(($oldEntry * $oldQty + $fillPrice * $fillQty) / $totalQty, 8);
        $totalUsdt = round($position->position_size_usdt + $additionalUsdt, 4);

        // Track accumulated entry fee
        $rates = $this->exchange->getCommissionRate($position->symbol);
        $addFee = $fillPrice * $fillQty * $rates['taker'];
        $oldEntryFee = $position->total_entry_fee
            ?? ($position->entry_price * $position->quantity * $rates['taker']);
        $totalEntryFee = round($oldEntryFee + $addFee, 8);

        // Replace SL/TP orders with new ones covering total quantity at new average entry
        // If SL/TP placement fails, we can't undo the market order — update position anyway
        $slTp = $this->calculateSlTp($newAvgEntry, $position->side);
        $slTpResult = ['sl_order_id' => null, 'tp_order_id' => null, 'sl' => $slTp['sl'], 'tp' => $slTp['tp']];
        try {
            $slTpResult = $this->replaceSlTpOrders($position, $newAvgEntry, $totalQty);
        } catch (\Throwable $e) {
            Log::error('Failed to replace SL/TP after adding to position — software SL/TP still active', [
                'symbol' => $position->symbol,
                'error' => $e->getMessage(),
            ]);
        }

        // Update position record
        $position->update([
            'entry_price' => $newAvgEntry,
            'quantity' => $totalQty,
            'position_size_usdt' => $totalUsdt,
            'stop_loss_price' => $slTpResult['sl'],
            'take_profit_price' => $slTpResult['tp'],
            'sl_order_id' => $slTpResult['sl_order_id'],
            'tp_order_id' => $slTpResult['tp_order_id'],
            'total_entry_fee' => $totalEntryFee,
            'layer_count' => $position->layer_count + 1,
        ]);

        Log::info('Position added to', [
            'symbol' => $position->symbol,
            'side' => $position->side,
            'old_entry' => $oldEntry,
            'new_entry' => $newAvgEntry,
            'added_qty' => $fillQty,
            'total_qty' => $totalQty,
            'total_usdt' => $totalUsdt,
            'new_sl' => $slTpResult['sl'],
            'new_tp' => $slTpResult['tp'],
            'layer_count' => $position->layer_count,
        ]);

        return $position;
    }

    /**
     * Cancel old SL/TP orders and place new ones for a position.
     * Used by both openPosition() and addToPosition().
     *
     * @return array{sl_order_id: ?string, tp_order_id: ?string, sl: float, tp: float}
     * @throws \Throwable If SL/TP placement fails after retry
     */
    private function replaceSlTpOrders(Position $position, float $entryPrice, float $totalQuantity): array
    {
        // Cancel existing SL/TP orders (if any)
        if ($position->sl_order_id) {
            try {
                $this->exchange->cancelOrder($position->symbol, $position->sl_order_id);
            } catch (\Throwable $e) {
                Log::warning('Failed to cancel old SL order', [
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
                Log::warning('Failed to cancel old TP order', [
                    'symbol' => $position->symbol,
                    'tp_order_id' => $position->tp_order_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Calculate new SL/TP prices
        $slTp = $this->calculateSlTp($entryPrice, $position->side);

        // Place new SL/TP orders with retry
        $slOrderId = null;
        $tpOrderId = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                if ($slOrderId === null) {
                    $slResult = $this->exchange->setStopLoss(
                        $position->symbol, $slTp['sl'], $totalQuantity, $position->side
                    );
                    $slOrderId = $slResult['orderId'] ?? null;
                }

                if ($tpOrderId === null) {
                    $tpResult = $this->exchange->setTakeProfit(
                        $position->symbol, $slTp['tp'], $totalQuantity, $position->side
                    );
                    $tpOrderId = $tpResult['orderId'] ?? null;
                }

                // Both placed successfully
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt === 1) {
                    Log::warning('SL/TP placement failed, retrying...', [
                        'symbol' => $position->symbol,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                    usleep(1_000_000); // 1 second retry delay
                }
            }
        }

        // If still failed after retry, log error but don't throw for addToPosition
        // For openPosition, the caller wraps this in try/catch and closes the position
        if ($slOrderId === null || $tpOrderId === null) {
            if ($lastError) {
                throw $lastError;
            }
        }

        return [
            'sl_order_id' => $slOrderId,
            'tp_order_id' => $tpOrderId,
            'sl' => $slTp['sl'],
            'tp' => $slTp['tp'],
        ];
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
