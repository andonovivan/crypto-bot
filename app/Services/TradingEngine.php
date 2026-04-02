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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class TradingEngine
{
    public function __construct(
        private ExchangeInterface $exchange,
        private ?WaveScanner $waveScanner = null,
    ) {}

    /**
     * Open a position in the given direction based on any signal type.
     * Accepts WaveSignal, TrendSignal, PumpSignal, or any object with symbol/atr_value/score.
     */
    public function openPosition(object $signal, string $direction, string $settingsPrefix = ''): ?Position
    {
        $maxPositions = Settings::get('max_positions');
        $leverage = (int) Settings::get('leverage');
        $positionSizeUsdt = (float) Settings::get('position_size_usdt');
        $isDryRun = (bool) Settings::get('dry_run');

        // Determine expiry based on strategy
        $strategy = (string) Settings::get('strategy') ?: 'wave';
        if ($strategy === 'wave') {
            $maxHoldMinutes = (int) Settings::get('wave_max_hold_minutes');
            $expiresAt = now()->addMinutes($maxHoldMinutes);
        } elseif ($strategy === 'staircase') {
            $maxHoldMinutes = (int) Settings::get('staircase_max_hold_minutes') ?: 1440;
            $expiresAt = now()->addMinutes($maxHoldMinutes);
        } else {
            $maxHoldHours = (int) Settings::get($settingsPrefix . 'max_hold_hours');
            $expiresAt = now()->addHours($maxHoldHours);
        }

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
            return null;
        }

        // Check available margin before opening
        $requiredMargin = $positionSizeUsdt / max($leverage, 1);
        $accountData = $this->exchange->getAccountData();
        if ($accountData['availableBalance'] < $requiredMargin) {
            Log::warning('Insufficient available balance for new position', [
                'symbol' => $signal->symbol,
                'required_margin' => $requiredMargin,
                'available_balance' => $accountData['availableBalance'],
            ]);
            return null;
        }

        // Verify the symbol is still tradable
        if (! $this->exchange->isTradable($signal->symbol)) {
            Log::warning('Symbol not tradable, skipping', ['symbol' => $signal->symbol]);
            if ($signal instanceof Model) {
                $signal->update(['status' => SignalStatus::Skipped]);
            }
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

            // ATR/fee viability check — skip if fee floor pushes TP beyond reachable ATR range
            $atrValue = $signal->atr_value ?? null;
            if ($atrValue > 0 && $strategy === 'wave') {
                $maxTpAtr = (float) Settings::get('wave_max_tp_atr') ?: 2.0;

                try {
                    $rates = $this->exchange->getCommissionRate($signal->symbol);
                    $takerRate = $rates['taker'];
                } catch (\Throwable) {
                    $takerRate = (float) Settings::get('dry_run_fee_rate') ?: 0.0005;
                }
                $feeFloorMultiplier = (float) Settings::get('wave_fee_floor_multiplier') ?: 2.5;
                $minTpDistance = $price * 2 * $takerRate * $feeFloorMultiplier;
                $effectiveTpInAtr = $minTpDistance / $atrValue;

                if ($effectiveTpInAtr > $maxTpAtr) {
                    Log::info('Trade skipped — ATR too low for fees', [
                        'symbol' => $signal->symbol,
                        'direction' => $direction,
                        'atr' => round($atrValue, 6),
                        'fee_floor_tp' => round($minTpDistance, 6),
                        'effective_tp_atr' => round($effectiveTpInAtr, 2),
                        'max_tp_atr' => $maxTpAtr,
                        'price' => $price,
                    ]);
                    return null;
                }
            }

            // Open position in the correct direction
            $order = $direction === 'LONG'
                ? $this->exchange->openLong($signal->symbol, $quantity)
                : $this->exchange->openShort($signal->symbol, $quantity);

            $entryPrice = $order['price'] > 0 ? $order['price'] : $price;

            // Calculate SL/TP — ATR-based if available, fixed % fallback
            $slTp = $this->calculateSlTp($entryPrice, $direction, $atrValue, $settingsPrefix, $signal->score ?? 0, $signal->symbol);

            // Place stop-loss and take-profit orders
            try {
                $this->exchange->setStopLoss($signal->symbol, $slTp['sl'], $quantity, $direction);
                $this->exchange->setTakeProfit($signal->symbol, $slTp['tp'], $quantity, $direction);
            } catch (\Throwable $e) {
                Log::warning('Failed to set SL/TP orders', [
                    'symbol' => $signal->symbol,
                    'error' => $e->getMessage(),
                ]);
            }

            // Determine which signal FK to set
            $signalFk = [];
            if ($signal instanceof PumpSignal) {
                $signalFk = ['pump_signal_id' => $signal->id];
            } elseif ($signal instanceof TrendSignal) {
                $signalFk = ['trend_signal_id' => $signal->id];
            }
            // WaveSignal has no FK — ephemeral

            $position = Position::create(array_merge($signalFk, [
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
                'atr_value' => $atrValue,
                'status' => PositionStatus::Open,
                'exchange_order_id' => $order['orderId'],
                'is_dry_run' => $isDryRun,
                'opened_at' => now(),
                'expires_at' => $expiresAt,
            ]));

            // Update signal status if it's a DB model
            if ($signal instanceof Model) {
                $signal->update(['status' => SignalStatus::Traded]);
            }

            Log::info('Position opened', [
                'symbol' => $signal->symbol,
                'side' => $direction,
                'entry_price' => $entryPrice,
                'quantity' => $order['quantity'],
                'stop_loss' => $slTp['sl'],
                'take_profit' => $slTp['tp'],
                'atr' => $atrValue,
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
     * Check a single position and close if SL/TP/trailing/expiry hit.
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

        // Breakeven protection: move SL to entry price once fees are covered
        if (! $position->breakeven_activated) {
            try {
                $rates = $this->exchange->getCommissionRate($position->symbol);
                $takerRate = $rates['taker'];
            } catch (\Throwable) {
                $takerRate = (float) Settings::get('dry_run_fee_rate') ?: 0.0005;
            }

            $feeCoverDistance = $position->entry_price * 2 * $takerRate;
            $profitFromEntry = $isLong
                ? $currentPrice - $position->entry_price
                : $position->entry_price - $currentPrice;

            if ($profitFromEntry >= $feeCoverDistance) {
                $breakevenSl = $position->entry_price;

                try {
                    $this->exchange->cancelOrders($position->symbol);
                    $this->exchange->setStopLoss($position->symbol, $breakevenSl, $position->quantity, $position->side);
                    $this->exchange->setTakeProfit($position->symbol, $position->take_profit_price, $position->quantity, $position->side);
                } catch (\Throwable $e) {
                    Log::warning('Failed to set breakeven SL', ['error' => $e->getMessage()]);
                }

                $position->update([
                    'stop_loss_price' => $breakevenSl,
                    'breakeven_activated' => true,
                ]);

                Log::info('Breakeven SL activated', [
                    'symbol' => $position->symbol,
                    'side' => $position->side,
                    'entry' => $position->entry_price,
                    'current' => $currentPrice,
                    'fee_distance' => round($feeCoverDistance, 6),
                ]);
            }
        }

        // ATR-based trailing stop (Wave only — Staircase uses fixed TP, no trailing)
        $strategy = (string) Settings::get('strategy') ?: 'wave';
        $atr = $position->atr_value;
        if ($strategy !== 'staircase' && $atr > 0) {
            $activationAtr = (float) Settings::get('wave_trailing_activation_atr') ?: 0.3;
            $trailDistAtr = (float) Settings::get('wave_trailing_distance_atr') ?: 0.3;

            $profitDistance = $isLong
                ? $bestPrice - $position->entry_price
                : $position->entry_price - $bestPrice;

            if ($profitDistance >= $activationAtr * $atr) {
                $trailingStopPrice = $isLong
                    ? $bestPrice - ($trailDistAtr * $atr)
                    : $bestPrice + ($trailDistAtr * $atr);

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
                    ]);
                }
            }
        }

        // Check stop-loss
        $slHit = $isLong
            ? ($position->stop_loss_price && $currentPrice <= $position->stop_loss_price)
            : ($position->stop_loss_price && $currentPrice >= $position->stop_loss_price);

        if ($slHit) {
            $reason = $position->breakeven_activated ? CloseReason::Breakeven : CloseReason::StopLoss;
            $this->closePosition($position, $currentPrice, $reason);
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
     * Check if a DCA layer should be added to a position.
     */
    public function checkDCA(Position $position, float $currentPrice): void
    {
        $maxLayers = (int) Settings::get('dca_max_layers');
        $maxPositionUsdt = (float) Settings::get('max_position_usdt');
        $positionSizeUsdt = (float) Settings::get('position_size_usdt');
        $atr = $position->atr_value;

        // Skip if no ATR (can't calculate DCA trigger)
        if ($atr <= 0) {
            return;
        }

        // Skip if at max layers
        if ($position->layer_count >= $maxLayers) {
            return;
        }

        // Skip if at max exposure
        if ($position->position_size_usdt >= $maxPositionUsdt) {
            return;
        }

        $isLong = $position->side === 'LONG';

        // DCA trigger: price moved N x dcaTriggerAtr x ATR against current avg entry
        $dcaTriggerAtr = (float) Settings::get('wave_dca_trigger_atr') ?: 0.5;
        $layerDistance = $dcaTriggerAtr * $atr * $position->layer_count;
        $triggerPrice = $isLong
            ? $position->entry_price - $layerDistance
            : $position->entry_price + $layerDistance;

        $triggered = $isLong
            ? $currentPrice <= $triggerPrice
            : $currentPrice >= $triggerPrice;

        if (! $triggered) {
            return;
        }

        // Validate wave is still intact before DCA
        if ($this->waveScanner && ! $this->waveScanner->isWaveIntact($position->symbol, $position->side)) {
            Log::info('DCA skipped — wave broken', [
                'symbol' => $position->symbol,
                'side' => $position->side,
                'layer' => $position->layer_count + 1,
            ]);
            return;
        }

        // Check margin
        $leverage = $position->leverage > 0 ? $position->leverage : 1;
        // Layer sizing: 75% for layer 2, 50% for layer 3+
        $layerMultiplier = $position->layer_count === 1 ? 0.75 : 0.50;
        $layerSizeUsdt = $positionSizeUsdt * $layerMultiplier;

        // Cap at max exposure
        $remainingBudget = $maxPositionUsdt - $position->position_size_usdt;
        $layerSizeUsdt = min($layerSizeUsdt, $remainingBudget);

        if ($layerSizeUsdt < 1) {
            return;
        }

        $requiredMargin = $layerSizeUsdt / $leverage;
        $accountData = $this->exchange->getAccountData();
        if ($accountData['availableBalance'] < $requiredMargin) {
            return;
        }

        try {
            $quantity = $this->exchange->calculateQuantity($position->symbol, $layerSizeUsdt, $currentPrice);
            if ($quantity <= 0) {
                return;
            }

            // Open DCA order
            $order = $isLong
                ? $this->exchange->openLong($position->symbol, $quantity)
                : $this->exchange->openShort($position->symbol, $quantity);

            $fillPrice = $order['price'] > 0 ? $order['price'] : $currentPrice;
            $fillQty = $order['quantity'] > 0 ? $order['quantity'] : $quantity;

            // Calculate new weighted average entry
            $oldNotional = $position->entry_price * $position->quantity;
            $newNotional = $fillPrice * $fillQty;
            $totalQty = $position->quantity + $fillQty;
            $avgEntry = ($oldNotional + $newNotional) / $totalQty;

            // Recalculate SL/TP from new average entry
            $slTp = $this->calculateSlTp($avgEntry, $position->side, $atr, '', 0, $position->symbol);

            // Cancel old SL/TP orders and set new ones
            try {
                $this->exchange->cancelOrders($position->symbol);
                $this->exchange->setStopLoss($position->symbol, $slTp['sl'], $totalQty, $position->side);
                $this->exchange->setTakeProfit($position->symbol, $slTp['tp'], $totalQty, $position->side);
            } catch (\Throwable $e) {
                Log::warning('Failed to update SL/TP after DCA', ['error' => $e->getMessage()]);
            }

            $position->update([
                'entry_price' => round($avgEntry, 8),
                'quantity' => $totalQty,
                'position_size_usdt' => $position->position_size_usdt + $layerSizeUsdt,
                'stop_loss_price' => $slTp['sl'],
                'take_profit_price' => $slTp['tp'],
                'layer_count' => $position->layer_count + 1,
                'best_price' => $avgEntry, // Reset best price to new avg entry
                'breakeven_activated' => false, // Reset breakeven — entry changed
            ]);

            Log::info('DCA layer added', [
                'symbol' => $position->symbol,
                'side' => $position->side,
                'layer' => $position->layer_count,
                'fill_price' => $fillPrice,
                'new_avg_entry' => round($avgEntry, 8),
                'total_qty' => $totalQty,
                'total_size_usdt' => $position->position_size_usdt,
                'new_sl' => $slTp['sl'],
                'new_tp' => $slTp['tp'],
            ]);

        } catch (\Throwable $e) {
            Log::error('DCA order failed', [
                'symbol' => $position->symbol,
                'layer' => $position->layer_count + 1,
                'error' => $e->getMessage(),
            ]);
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
            'layers' => $position->layer_count,
            'pnl' => $pnl,
            'fees' => $totalFees,
            'pnl_pct' => round($pnlPct, 2) . '%',
        ]);

        return $trade;
    }

    /**
     * Calculate SL/TP prices. ATR-based from wave settings, fixed % fallback.
     *
     * @return array{sl: float, tp: float}
     */
    private function calculateSlTp(float $entryPrice, string $direction, ?float $atr, string $settingsPrefix, int $score, string $symbol = ''): array
    {
        // Staircase: fixed-percentage SL/TP (not ATR-based)
        $strategy = (string) Settings::get('strategy') ?: 'wave';
        if ($strategy === 'staircase') {
            $tpPct = (float) Settings::get('staircase_take_profit_pct') ?: 1.68;
            $slPct = (float) Settings::get('staircase_stop_loss_pct') ?: 5.0;

            if ($direction === 'LONG') {
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

        // ATR-based SL/TP from wave settings
        if ($atr > 0) {
            $atrPctOfPrice = ($atr / $entryPrice) * 100;

            // Sanity check: ATR should be at least 0.05% of price
            if ($atrPctOfPrice >= 0.05) {
                $slMultiplier = (float) Settings::get('wave_sl_atr_multiplier') ?: 1.0;
                $tpMultiplier = (float) Settings::get('wave_tp_atr_multiplier') ?: 0.5;

                $slDistance = $slMultiplier * $atr;
                $tpDistance = $tpMultiplier * $atr;

                // Ensure TP covers round-trip fees with a 50% margin
                // Min profitable TP distance = price * 2 * takerRate * 1.5
                try {
                    $rates = $this->exchange->getCommissionRate($symbol);
                    $takerRate = $rates['taker'];
                } catch (\Throwable) {
                    $takerRate = (float) Settings::get('dry_run_fee_rate') ?: 0.0005;
                }
                $feeFloorMultiplier = (float) Settings::get('wave_fee_floor_multiplier') ?: 2.5;
                $minTpDistance = $entryPrice * 2 * $takerRate * $feeFloorMultiplier;

                if ($tpDistance < $minTpDistance) {
                    Log::info('TP distance adjusted to cover fees', [
                        'original' => round($tpDistance, 6),
                        'minimum' => round($minTpDistance, 6),
                        'entry' => $entryPrice,
                        'taker_rate' => $takerRate,
                    ]);
                    $tpDistance = $minTpDistance;
                }

                if ($direction === 'LONG') {
                    return [
                        'sl' => round($entryPrice - $slDistance, 8),
                        'tp' => round($entryPrice + $tpDistance, 8),
                    ];
                }

                return [
                    'sl' => round($entryPrice + $slDistance, 8),
                    'tp' => round($entryPrice - $tpDistance, 8),
                ];
            }
        }

        // Fallback: fixed 1% SL, 0.5% TP (reasonable defaults for scalping)
        $stopLossPct = 1.0;
        $takeProfitPct = 0.5;

        if ($direction === 'LONG') {
            return [
                'sl' => $entryPrice * (1 - $stopLossPct / 100),
                'tp' => $entryPrice * (1 + $takeProfitPct / 100),
            ];
        }

        return [
            'sl' => $entryPrice * (1 + $stopLossPct / 100),
            'tp' => $entryPrice * (1 - $takeProfitPct / 100),
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
