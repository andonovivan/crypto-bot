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
        private ?TrendScanner $trendScanner = null,
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

            // Calculate SL/TP — ATR-based if available, fixed % fallback
            $atrValue = $signal->atr_value ?? null;
            $slTp = $this->calculateSlTp($entryPrice, $direction, $atrValue, $settingsPrefix, $signal->score ?? 0);

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
            $signalFk = $signal instanceof PumpSignal
                ? ['pump_signal_id' => $signal->id]
                : ['trend_signal_id' => $signal->id];

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
                'expires_at' => now()->addHours($maxHoldHours),
            ]));

            $signal->update(['status' => SignalStatus::Traded]);

            Log::info('Position opened', [
                'symbol' => $signal->symbol,
                'side' => $direction,
                'entry_price' => $entryPrice,
                'quantity' => $order['quantity'],
                'stop_loss' => $slTp['sl'],
                'take_profit' => $slTp['tp'],
                'atr' => $atrValue,
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
     *
     * @return array<int, Position> Positions opened
     */
    public function retryConfirmedSignals(): array
    {
        $openedPositions = [];

        $confirmedSignals = PumpSignal::where('status', SignalStatus::ReversalConfirmed)
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

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
     * Also checks DCA opportunities.
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

        // DCA check — separate pass after SL/TP to avoid adding to positions about to close
        if ((bool) Settings::get('dca_enabled')) {
            foreach ($positions->fresh() as $position) {
                if ($position->status !== PositionStatus::Open) {
                    continue; // Was closed in the check above
                }

                try {
                    $currentPrice = $prices[$position->symbol] ?? null;
                    if ($currentPrice !== null) {
                        $this->checkDCA($position, $currentPrice);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed DCA check', [
                        'position_id' => $position->id,
                        'symbol' => $position->symbol,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Check a single position and close if SL/TP/expiry hit.
     */
    private function checkPosition(Position $position, float $currentPrice): void
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

        // Breakeven stop: once profit >= 0.5x ATR, move SL to entry
        $atr = $position->atr_value;
        if ($atr > 0) {
            $breakevenThreshold = 0.5 * $atr * $position->quantity;
            if ($unrealizedPnl >= $breakevenThreshold) {
                $shouldMoveToBreakeven = $isLong
                    ? $position->stop_loss_price < $position->entry_price
                    : $position->stop_loss_price > $position->entry_price;

                if ($shouldMoveToBreakeven) {
                    $position->update(['stop_loss_price' => $position->entry_price]);
                    Log::info('Breakeven stop activated', [
                        'symbol' => $position->symbol,
                        'side' => $position->side,
                        'entry_price' => $position->entry_price,
                    ]);
                }
            }
        }

        // Trailing stop: ATR-based if available, else percentage-based
        $settingsPrefix = $position->trend_signal_id ? 'trend_' : '';

        if ($atr > 0) {
            // ATR-based trailing: activate at 1x ATR profit, trail at 0.5x ATR
            $profitDistance = $isLong
                ? $bestPrice - $position->entry_price
                : $position->entry_price - $bestPrice;

            if ($profitDistance >= $atr) {
                $trailingStopPrice = $isLong
                    ? $bestPrice - (0.5 * $atr)
                    : $bestPrice + (0.5 * $atr);

                $shouldUpdate = $isLong
                    ? $trailingStopPrice > $position->stop_loss_price
                    : $trailingStopPrice < $position->stop_loss_price;

                if ($shouldUpdate) {
                    $position->update(['stop_loss_price' => $trailingStopPrice]);
                    Log::info('ATR trailing stop updated', [
                        'symbol' => $position->symbol,
                        'side' => $position->side,
                        'best_price' => $bestPrice,
                        'new_stop_loss' => $trailingStopPrice,
                    ]);
                }
            }
        } else {
            // Fallback: percentage-based trailing
            $trailingActivationPct = (float) Settings::get($settingsPrefix . 'trailing_stop_activation_pct');
            $trailingStopPct = (float) Settings::get($settingsPrefix . 'trailing_stop_pct');

            if ($trailingActivationPct > 0 && $trailingStopPct > 0 && $position->entry_price > 0) {
                $profitPct = $isLong
                    ? (($bestPrice - $position->entry_price) / $position->entry_price) * 100
                    : (($position->entry_price - $bestPrice) / $position->entry_price) * 100;

                if ($profitPct >= $trailingActivationPct) {
                    $trailingStopPrice = $isLong
                        ? $bestPrice * (1 - $trailingStopPct / 100)
                        : $bestPrice * (1 + $trailingStopPct / 100);

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
     * Check if a DCA layer should be added to a position.
     */
    private function checkDCA(Position $position, float $currentPrice): void
    {
        $maxLayers = (int) Settings::get('dca_max_layers');
        $maxPositionUsdt = (float) Settings::get('max_position_usdt');
        $positionSizeUsdt = (float) Settings::get('position_size_usdt');
        $isDryRun = (bool) Settings::get('dry_run');
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

        // DCA trigger: price moved N x ATR against current avg entry (N = layer_count)
        $layerDistance = $atr * $position->layer_count;
        $triggerPrice = $isLong
            ? $position->entry_price - $layerDistance
            : $position->entry_price + $layerDistance;

        $triggered = $isLong
            ? $currentPrice <= $triggerPrice
            : $currentPrice >= $triggerPrice;

        if (! $triggered) {
            return;
        }

        // Validate trend is still aligned before DCA
        if ($this->trendScanner && ! $this->trendScanner->isAlignmentValid($position->symbol, $position->side)) {
            Log::info('DCA skipped — trend alignment lost', [
                'symbol' => $position->symbol,
                'side' => $position->side,
                'layer' => $position->layer_count + 1,
            ]);
            return;
        }

        // Check margin
        $leverage = $position->leverage > 0 ? $position->leverage : 1;
        // Layer sizing: 75% for layer 2, 50% for layer 3
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
            $slTp = $this->calculateSlTp($avgEntry, $position->side, $atr, 'trend_', 0);

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
     * Calculate SL/TP prices. ATR-based if available, fixed % fallback.
     *
     * @return array{sl: float, tp: float}
     */
    private function calculateSlTp(float $entryPrice, string $direction, ?float $atr, string $settingsPrefix, int $score): array
    {
        // ATR-based SL/TP
        if ($atr > 0) {
            $atrPctOfPrice = ($atr / $entryPrice) * 100;

            // Sanity check: ATR should be at least 0.1% of price
            if ($atrPctOfPrice >= 0.1) {
                $slDistance = 1.5 * $atr;
                // Strong signals get wider TP (2x ATR), normal signals 1x ATR
                $tpDistance = $score >= 90 ? (2.0 * $atr) : (1.0 * $atr);

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

        // Fallback: fixed percentage
        $stopLossPct = (float) Settings::get($settingsPrefix . 'stop_loss_pct');
        $takeProfitPct = (float) Settings::get($settingsPrefix . 'take_profit_pct');

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
