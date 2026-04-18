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

    public function openShort(ShortSignal $signal): ?Position
    {
        $maxPositions = (int) Settings::get('max_positions') ?: 10;
        $leverage = (int) Settings::get('leverage') ?: 25;
        $maxHoldMinutes = (int) Settings::get('max_hold_minutes') ?: 120;
        $isDryRun = (bool) Settings::get('dry_run');

        if (Position::open()->count() >= $maxPositions) {
            Log::warning('Max positions reached, skipping', ['symbol' => $signal->symbol]);
            return null;
        }

        if (Position::open()->where('symbol', $signal->symbol)->exists()) {
            return null;
        }

        $accountData = $this->exchange->getAccountData();
        $positionSizePct = (float) Settings::get('position_size_pct') ?: 10.0;
        $margin = $accountData['walletBalance'] * ($positionSizePct / 100);
        $positionSizeUsdt = round($margin * max($leverage, 1), 2);

        if ($accountData['availableBalance'] < $margin) {
            Log::warning('Insufficient available balance for new position', [
                'symbol' => $signal->symbol,
                'required_margin' => $margin,
                'available_balance' => $accountData['availableBalance'],
            ]);
            return null;
        }

        if (! $this->exchange->isTradable($signal->symbol)) {
            Log::warning('Symbol not tradable', ['symbol' => $signal->symbol]);
            return null;
        }

        try {
            $price = $this->exchange->getPrice($signal->symbol);
            $this->exchange->setLeverage($signal->symbol, $leverage);

            $quantity = $this->exchange->calculateQuantity($signal->symbol, $positionSizeUsdt, $price);
            if ($quantity <= 0) {
                Log::warning('Calculated quantity is zero', ['symbol' => $signal->symbol]);
                return null;
            }

            $order = $this->exchange->openShort($signal->symbol, $quantity);
            $entryPrice = $order['price'] > 0 ? $order['price'] : $price;

            try {
                $slTp = $this->placeBrackets($signal->symbol, $entryPrice, $order['quantity']);
            } catch (\Throwable $e) {
                Log::error('Failed to set SL/TP — closing position for safety', [
                    'symbol' => $signal->symbol,
                    'error' => $e->getMessage(),
                ]);

                try {
                    $this->exchange->closeShort($signal->symbol, $order['quantity']);
                } catch (\Throwable $closeErr) {
                    Log::error('CRITICAL: Failed to close unprotected position', [
                        'symbol' => $signal->symbol,
                        'error' => $closeErr->getMessage(),
                    ]);
                }
                return null;
            }

            $rates = $this->exchange->getCommissionRate($signal->symbol);
            $initialEntryFee = round($entryPrice * $order['quantity'] * $rates['taker'], 8);

            $position = Position::create([
                'symbol' => $signal->symbol,
                'side' => 'SHORT',
                'entry_price' => $entryPrice,
                'quantity' => $order['quantity'],
                'position_size_usdt' => $positionSizeUsdt,
                'stop_loss_price' => $slTp['sl'],
                'take_profit_price' => $slTp['tp'],
                'current_price' => $entryPrice,
                'leverage' => $leverage,
                'status' => PositionStatus::Open,
                'exchange_order_id' => $order['orderId'],
                'sl_order_id' => $slTp['sl_order_id'],
                'tp_order_id' => $slTp['tp_order_id'],
                'total_entry_fee' => $initialEntryFee,
                'is_dry_run' => $isDryRun,
                'opened_at' => now(),
                'expires_at' => now()->addMinutes($maxHoldMinutes),
            ]);

            Log::info('SHORT opened', [
                'symbol' => $signal->symbol,
                'entry' => $entryPrice,
                'qty' => $order['quantity'],
                'sl' => $slTp['sl'],
                'tp' => $slTp['tp'],
                'reason' => $signal->reason,
                '24h' => round($signal->priceChangePct, 2) . '%',
            ]);

            return $position;
        } catch (\Throwable $e) {
            Log::error('Failed to open SHORT', [
                'symbol' => $signal->symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function checkPosition(Position $position, float $currentPrice): void
    {
        $unrealizedPnl = $this->calculatePnl($position, $currentPrice);

        $position->update([
            'current_price' => $currentPrice,
            'unrealized_pnl' => $unrealizedPnl,
        ]);

        $slHit = $position->side === 'LONG'
            ? ($position->stop_loss_price && $currentPrice <= $position->stop_loss_price)
            : ($position->stop_loss_price && $currentPrice >= $position->stop_loss_price);

        if ($slHit) {
            $this->closePosition($position, $currentPrice, CloseReason::StopLoss);
            return;
        }

        $tpHit = $position->side === 'LONG'
            ? ($position->take_profit_price && $currentPrice >= $position->take_profit_price)
            : ($position->take_profit_price && $currentPrice <= $position->take_profit_price);

        if ($tpHit) {
            $this->closePosition($position, $currentPrice, CloseReason::TakeProfit);
            return;
        }

        if ($position->expires_at && now()->gte($position->expires_at)) {
            $this->closePosition($position, $currentPrice, CloseReason::Expired);
        }
    }

    public function closePosition(Position $position, ?float $exitPrice = null, CloseReason $reason = CloseReason::Manual): Trade
    {
        if ($exitPrice === null) {
            $exitPrice = $this->exchange->getPrice($position->symbol);
        }

        $this->cancelBrackets($position);

        $order = $position->side === 'LONG'
            ? $this->exchange->closeLong($position->symbol, $position->quantity)
            : $this->exchange->closeShort($position->symbol, $position->quantity);

        $actualExitPrice = $order['price'] > 0 ? $order['price'] : $exitPrice;

        $rawPnl = $this->calculatePnl($position, $actualExitPrice);
        $pnlPct = $this->calculatePnlPct($position, $actualExitPrice);

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

        $fundingFee = $position->funding_fee ?? 0;

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
            'funding_fee' => round($fundingFee, 4),
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

    public function addToPosition(Position $position, float $additionalUsdt): Position
    {
        if ($position->status !== PositionStatus::Open) {
            throw new \RuntimeException('Cannot add to a position that is not open');
        }
        if ($additionalUsdt <= 0) {
            throw new \RuntimeException('Additional amount must be greater than zero');
        }

        $leverage = $position->leverage > 0 ? $position->leverage : 1;
        $requiredMargin = $additionalUsdt / $leverage;
        $accountData = $this->exchange->getAccountData();

        if ($accountData['availableBalance'] < $requiredMargin) {
            throw new \RuntimeException(
                "Insufficient balance: need \${$requiredMargin} margin, have \${$accountData['availableBalance']} available"
            );
        }

        $currentPrice = $this->exchange->getPrice($position->symbol);
        $additionalQty = $this->exchange->calculateQuantity($position->symbol, $additionalUsdt, $currentPrice);

        if ($additionalQty <= 0) {
            throw new \RuntimeException('Amount too small — calculated quantity is zero');
        }

        $order = $position->side === 'LONG'
            ? $this->exchange->openLong($position->symbol, $additionalQty)
            : $this->exchange->openShort($position->symbol, $additionalQty);

        $fillPrice = $order['price'] > 0 ? $order['price'] : $currentPrice;
        $fillQty = $order['quantity'];

        $oldQty = $position->quantity;
        $totalQty = $oldQty + $fillQty;
        $newAvgEntry = round(($position->entry_price * $oldQty + $fillPrice * $fillQty) / $totalQty, 8);
        $totalUsdt = round($position->position_size_usdt + $additionalUsdt, 4);

        $rates = $this->exchange->getCommissionRate($position->symbol);
        $addFee = $fillPrice * $fillQty * $rates['taker'];
        $oldEntryFee = $position->total_entry_fee
            ?? ($position->entry_price * $position->quantity * $rates['taker']);
        $totalEntryFee = round($oldEntryFee + $addFee, 8);

        $this->cancelBrackets($position);
        try {
            $slTp = $this->placeBrackets($position->symbol, $newAvgEntry, $totalQty, $position->side);
        } catch (\Throwable $e) {
            Log::error('Failed to replace SL/TP after add', [
                'symbol' => $position->symbol,
                'error' => $e->getMessage(),
            ]);
            $calc = $this->calculateSlTp($newAvgEntry, $position->side);
            $slTp = ['sl_order_id' => null, 'tp_order_id' => null, 'sl' => $calc['sl'], 'tp' => $calc['tp']];
        }

        $position->update([
            'entry_price' => $newAvgEntry,
            'quantity' => $totalQty,
            'position_size_usdt' => $totalUsdt,
            'stop_loss_price' => $slTp['sl'],
            'take_profit_price' => $slTp['tp'],
            'sl_order_id' => $slTp['sl_order_id'],
            'tp_order_id' => $slTp['tp_order_id'],
            'total_entry_fee' => $totalEntryFee,
        ]);

        Log::info('Position added to', [
            'symbol' => $position->symbol,
            'new_entry' => $newAvgEntry,
            'total_qty' => $totalQty,
        ]);

        return $position;
    }

    /**
     * @return array{trade: Trade, position: ?Position}
     */
    public function reversePosition(Position $position): array
    {
        if ($position->status !== PositionStatus::Open) {
            throw new \RuntimeException('Cannot reverse a position that is not open');
        }

        $symbolPositionCount = Position::open()->where('symbol', $position->symbol)->count();
        if ($symbolPositionCount > 1) {
            throw new \RuntimeException(
                "Cannot reverse: {$position->symbol} has {$symbolPositionCount} open positions."
            );
        }

        $symbol = $position->symbol;
        $positionSizeUsdt = $position->position_size_usdt;
        $leverage = $position->leverage > 0 ? $position->leverage : 1;
        $isDryRun = $position->is_dry_run;
        $newDirection = $position->side === 'LONG' ? 'SHORT' : 'LONG';
        $maxHoldMinutes = (int) Settings::get('max_hold_minutes') ?: 120;

        $trade = $this->closePosition($position, reason: CloseReason::Reversed);

        try {
            $price = $this->exchange->getPrice($symbol);
            $requiredMargin = $positionSizeUsdt / $leverage;
            $accountData = $this->exchange->getAccountData();
            if ($accountData['availableBalance'] < $requiredMargin) {
                Log::warning('Insufficient balance for reverse leg', ['symbol' => $symbol]);
                return ['trade' => $trade, 'position' => null];
            }

            $this->exchange->setLeverage($symbol, $leverage);
            $quantity = $this->exchange->calculateQuantity($symbol, $positionSizeUsdt, $price);
            if ($quantity <= 0) {
                return ['trade' => $trade, 'position' => null];
            }

            $order = $newDirection === 'LONG'
                ? $this->exchange->openLong($symbol, $quantity)
                : $this->exchange->openShort($symbol, $quantity);

            $entryPrice = $order['price'] > 0 ? $order['price'] : $price;

            try {
                $slTp = $this->placeBrackets($symbol, $entryPrice, $order['quantity'], $newDirection);
            } catch (\Throwable $e) {
                Log::error('Reverse: failed to set SL/TP — closing for safety', [
                    'symbol' => $symbol, 'error' => $e->getMessage(),
                ]);
                try {
                    $newDirection === 'LONG'
                        ? $this->exchange->closeLong($symbol, $order['quantity'])
                        : $this->exchange->closeShort($symbol, $order['quantity']);
                } catch (\Throwable $closeErr) {
                    Log::error('CRITICAL: Failed to close unprotected reversed position', [
                        'symbol' => $symbol, 'error' => $closeErr->getMessage(),
                    ]);
                }
                return ['trade' => $trade, 'position' => null];
            }

            $rates = $this->exchange->getCommissionRate($symbol);
            $initialEntryFee = round($entryPrice * $order['quantity'] * $rates['taker'], 8);

            $newPosition = Position::create([
                'symbol' => $symbol,
                'side' => $newDirection,
                'entry_price' => $entryPrice,
                'quantity' => $order['quantity'],
                'position_size_usdt' => $positionSizeUsdt,
                'stop_loss_price' => $slTp['sl'],
                'take_profit_price' => $slTp['tp'],
                'current_price' => $entryPrice,
                'leverage' => $leverage,
                'status' => PositionStatus::Open,
                'exchange_order_id' => $order['orderId'],
                'sl_order_id' => $slTp['sl_order_id'],
                'tp_order_id' => $slTp['tp_order_id'],
                'total_entry_fee' => $initialEntryFee,
                'is_dry_run' => $isDryRun,
                'opened_at' => now(),
                'expires_at' => now()->addMinutes($maxHoldMinutes),
            ]);

            Log::info('Position reversed', [
                'symbol' => $symbol,
                'old_side' => $position->side,
                'new_side' => $newDirection,
            ]);

            return ['trade' => $trade, 'position' => $newPosition];
        } catch (\Throwable $e) {
            Log::error('Reverse: open leg failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
            return ['trade' => $trade, 'position' => null];
        }
    }

    /**
     * @return array{sl_order_id: ?string, tp_order_id: ?string, sl: float, tp: float}
     */
    private function placeBrackets(string $symbol, float $entryPrice, float $quantity, string $side = 'SHORT'): array
    {
        $slTp = $this->calculateSlTp($entryPrice, $side);

        $slOrderId = null;
        $tpOrderId = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                if ($slOrderId === null) {
                    $slResult = $this->exchange->setStopLoss($symbol, $slTp['sl'], $quantity, $side);
                    $slOrderId = $slResult['orderId'] ?? null;
                }
                if ($tpOrderId === null) {
                    $tpResult = $this->exchange->setTakeProfit($symbol, $slTp['tp'], $quantity, $side);
                    $tpOrderId = $tpResult['orderId'] ?? null;
                }
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt === 1) {
                    usleep(1_000_000);
                }
            }
        }

        if (($slOrderId === null || $tpOrderId === null) && $lastError) {
            throw $lastError;
        }

        return [
            'sl_order_id' => $slOrderId,
            'tp_order_id' => $tpOrderId,
            'sl' => $slTp['sl'],
            'tp' => $slTp['tp'],
        ];
    }

    private function cancelBrackets(Position $position): void
    {
        if (! $position->sl_order_id && ! $position->tp_order_id) {
            try {
                $this->exchange->cancelOrders($position->symbol);
            } catch (\Throwable $e) {
                Log::warning('Failed to cancel orders on close (legacy fallback)', [
                    'symbol' => $position->symbol, 'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        if ($position->sl_order_id) {
            try {
                $this->exchange->cancelOrder($position->symbol, $position->sl_order_id);
            } catch (\Throwable $e) {
                Log::warning('Failed to cancel SL order', [
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
                Log::warning('Failed to cancel TP order', [
                    'symbol' => $position->symbol,
                    'tp_order_id' => $position->tp_order_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array{sl: float, tp: float}
     */
    private function calculateSlTp(float $entryPrice, string $side = 'SHORT'): array
    {
        $tpPct = (float) Settings::get('take_profit_pct') ?: 2.0;
        $slPct = (float) Settings::get('stop_loss_pct') ?: 1.0;

        if ($side === 'LONG') {
            return [
                'sl' => round($entryPrice * (1 - $slPct / 100), 8),
                'tp' => round($entryPrice * (1 + $tpPct / 100), 8),
            ];
        }

        return [
            'sl' => round($entryPrice * (1 + $slPct / 100), 8),
            'tp' => round($entryPrice * (1 - $tpPct / 100), 8),
        ];
    }

    private function calculatePnl(Position $position, float $currentPrice): float
    {
        if ($position->side === 'LONG') {
            return round(($currentPrice - $position->entry_price) * $position->quantity, 4);
        }
        return round(($position->entry_price - $currentPrice) * $position->quantity, 4);
    }

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
