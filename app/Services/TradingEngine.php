<?php

namespace App\Services;

use App\Enums\CloseReason;
use App\Enums\PositionStatus;
use App\Models\Position;
use App\Models\Trade;
use App\Services\Exchange\ExchangeInterface;
use Illuminate\Support\Facades\DB;
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

        $exchange = $this->exchange->resolve();

        $accountData = $exchange->getAccountData();
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

        if (! $exchange->isTradable($signal->symbol)) {
            Log::warning('Symbol not tradable', ['symbol' => $signal->symbol]);
            return null;
        }

        $price = null;
        try {
            $price = $exchange->getPrice($signal->symbol);
            $exchange->setMarginType($signal->symbol, 'ISOLATED');
            $exchange->setLeverage($signal->symbol, $leverage);

            $quantity = $exchange->calculateQuantity($signal->symbol, $positionSizeUsdt, $price);
            if ($quantity <= 0) {
                Log::warning('Calculated quantity is zero', ['symbol' => $signal->symbol]);
                return null;
            }

            $order = $this->placeEntryOrder($exchange, $signal->symbol, $quantity, 'SHORT');
            $entryPrice = $order['price'] > 0 ? $order['price'] : $price;

            try {
                $slTp = $this->placeBrackets($exchange, $signal->symbol, $entryPrice, $order['quantity']);
            } catch (\Throwable $e) {
                Log::error('Failed to set SL/TP — closing position for safety', [
                    'symbol' => $signal->symbol,
                    'error' => $e->getMessage(),
                ]);

                // Any bracket that did land before the failure would otherwise linger as a
                // reduceOnly=true order on Binance. Best-effort cleanup.
                try {
                    $exchange->cancelOrders($signal->symbol);
                } catch (\Throwable $cancelErr) {
                    Log::warning('Failed to cancel stray bracket orders', [
                        'symbol' => $signal->symbol,
                        'error' => $cancelErr->getMessage(),
                    ]);
                }

                $closeErrMsg = null;
                try {
                    $exchange->closeShort($signal->symbol, $order['quantity']);
                } catch (\Throwable $closeErr) {
                    $closeErrMsg = $closeErr->getMessage();
                    Log::error('CRITICAL: Failed to close unprotected position', [
                        'symbol' => $signal->symbol,
                        'error' => $closeErrMsg,
                    ]);
                }

                $msg = 'Bracket placement failed: ' . $e->getMessage();
                if ($closeErrMsg) {
                    $msg .= ' | UNPROTECTED — close also failed: ' . $closeErrMsg;
                }
                $this->recordFailedEntry($signal, $leverage, $positionSizeUsdt, $entryPrice, $isDryRun, $msg, $order);
                return null;
            }

            $rates = $exchange->getCommissionRate($signal->symbol);
            $entryFeeRate = ($order['entry_type'] ?? 'MARKET') === 'LIMIT_MAKER'
                ? $rates['maker']
                : $rates['taker'];
            $initialEntryFee = round($entryPrice * $order['quantity'] * $entryFeeRate, 8);

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
                'entry_type' => $order['entry_type'] ?? 'MARKET',
            ]);

            return $position;
        } catch (\Throwable $e) {
            Log::error('Failed to open SHORT', [
                'symbol' => $signal->symbol,
                'error' => $e->getMessage(),
            ]);
            $this->recordFailedEntry(
                $signal,
                $leverage,
                $positionSizeUsdt,
                $price ?? 0.0,
                $isDryRun,
                'Entry rejected: ' . $e->getMessage(),
            );
            return null;
        }
    }

    /**
     * Persist a Failed position row so entry rejections show up in the trade history
     * instead of being silent log-only events. `$order` is optionally passed for the
     * post-entry / pre-bracket failure path where we did get a fill.
     */
    private function recordFailedEntry(
        ShortSignal $signal,
        int $leverage,
        float $positionSizeUsdt,
        float $price,
        bool $isDryRun,
        string $errorMessage,
        ?array $order = null,
    ): void {
        $entryPrice = $price;
        if ($order && ($order['price'] ?? 0) > 0) {
            $entryPrice = (float) $order['price'];
        }

        try {
            Position::create([
                'symbol' => $signal->symbol,
                'side' => 'SHORT',
                'entry_price' => $entryPrice,
                'quantity' => $order['quantity'] ?? 0,
                'position_size_usdt' => $positionSizeUsdt,
                'stop_loss_price' => 0,
                'take_profit_price' => 0,
                'current_price' => $price,
                'leverage' => $leverage,
                'status' => PositionStatus::Failed,
                'error_message' => $errorMessage,
                'exchange_order_id' => $order['orderId'] ?? null,
                'is_dry_run' => $isDryRun,
                'opened_at' => now(),
            ]);
        } catch (\Throwable $persistErr) {
            Log::error('Failed to persist Failed-entry row', [
                'symbol' => $signal->symbol,
                'error' => $persistErr->getMessage(),
            ]);
        }
    }

    public function checkPosition(Position $position, float $currentPrice): void
    {
        // Idempotency guard: caller may hold a stale row. Refresh from DB
        // and bail if the position has already been closed by a concurrent
        // caller (ws-driven check vs BotRun fallback, or manual close).
        $position->refresh();
        if ($position->status !== PositionStatus::Open) {
            return;
        }

        // Safety: if the operator flipped `dry_run` while this position was open,
        // the configured exchange no longer matches the one that opened it. Acting
        // now would close a real Binance position via DryRunExchange (or vice-
        // versa). Skip loudly — the operator must reconcile before anything else.
        if ($this->isModeMismatch($position)) {
            Log::warning('Skipping checkPosition: dry_run setting does not match position', [
                'position_id' => $position->id,
                'symbol' => $position->symbol,
                'position_is_dry_run' => $position->is_dry_run,
                'current_dry_run' => (bool) Settings::get('dry_run'),
            ]);
            return;
        }

        $unrealizedPnl = $this->calculatePnl($position, $currentPrice);

        $position->update([
            'current_price' => $currentPrice,
            'unrealized_pnl' => $unrealizedPnl,
        ]);

        // Dry-run: bot triggers SL/TP via price comparison (legacy behavior).
        // Live: Binance's STOP_MARKET / TAKE_PROFIT_MARKET orders handle SL/TP;
        // the user-data WS worker reconciles fills back into our DB.
        $isDryRun = (bool) Settings::get('dry_run');
        if ($isDryRun) {
            if ($this->slHit($position, $currentPrice)) {
                $this->closePosition($position, $currentPrice, CloseReason::StopLoss);
                return;
            }
            if ($this->tpHit($position, $currentPrice)) {
                $this->closePosition($position, $currentPrice, CloseReason::TakeProfit);
                return;
            }
        }

        // Expiry remains bot-driven in both modes — Binance has no time-based close.
        if ($position->expires_at && now()->gte($position->expires_at)) {
            $this->closePosition($position, $currentPrice, CloseReason::Expired);
        }
    }

    private function slHit(Position $position, float $currentPrice): bool
    {
        if (! $position->stop_loss_price) {
            return false;
        }
        return $position->side === 'LONG'
            ? $currentPrice <= $position->stop_loss_price
            : $currentPrice >= $position->stop_loss_price;
    }

    private function tpHit(Position $position, float $currentPrice): bool
    {
        if (! $position->take_profit_price) {
            return false;
        }
        return $position->side === 'LONG'
            ? $currentPrice >= $position->take_profit_price
            : $currentPrice <= $position->take_profit_price;
    }

    public function closePosition(Position $position, ?float $exitPrice = null, CloseReason $reason = CloseReason::Manual): Trade
    {
        // Idempotency guard: a concurrent ws-stream reconcile, safety poll, or
        // racing manual close may have already flipped this row. Return the
        // existing Trade rather than issuing a duplicate exchange call and
        // writing a second Trade row for the same close.
        $position->refresh();
        if ($position->status !== PositionStatus::Open) {
            $existing = Trade::where('position_id', $position->id)->latest('id')->first();
            if ($existing) {
                return $existing;
            }
            throw new \RuntimeException(
                "Position #{$position->id} is not open and has no recorded close trade"
            );
        }

        // Refuse if the current dry_run setting doesn't match the position's origin.
        if ($this->isModeMismatch($position)) {
            $current = (bool) Settings::get('dry_run');
            throw new \RuntimeException(sprintf(
                'Exchange-mode mismatch: position #%d is_dry_run=%s, current dry_run=%s. Flip dry_run back before closing.',
                $position->id,
                $position->is_dry_run ? 'true' : 'false',
                $current ? 'true' : 'false',
            ));
        }

        $exchange = $this->exchange->resolve();

        if ($exitPrice === null) {
            $exitPrice = $exchange->getPrice($position->symbol);
        }

        $this->cancelBrackets($exchange, $position);

        try {
            $order = $position->side === 'LONG'
                ? $exchange->closeLong($position->symbol, $position->quantity)
                : $exchange->closeShort($position->symbol, $position->quantity);
        } catch (\Throwable $e) {
            // "Already flat" handling: Binance may have fired a bracket SL/TP just
            // before this manual/expiry close. Look up the actual fill and reconcile.
            if ($this->looksLikeAlreadyFlat($e)) {
                $reconciled = $this->reconcileFromBrackets($position, $reason);
                if ($reconciled !== null) {
                    return $reconciled;
                }
                // Brackets didn't fill either — synthesize a close at last known price
                // using the caller's reason. No exchange orderId available.
                Log::warning('Close target already flat but no bracket fill found — synthesizing', [
                    'symbol' => $position->symbol,
                    'reason' => $reason->value,
                ]);
                return $this->finalizeClose($position, $exitPrice, '', $reason);
            }
            throw $e;
        }

        $actualExitPrice = $order['price'] > 0 ? $order['price'] : $exitPrice;

        return $this->finalizeClose($position, $actualExitPrice, (string) $order['orderId'], $reason);
    }

    /**
     * Record a close that was triggered by a Binance bracket order fill (SL or TP).
     * The user-data WS worker calls this on ORDER_TRADE_UPDATE X=FILLED events
     * whose orderId matches a known Position.sl_order_id or Position.tp_order_id.
     *
     * Idempotent: if the position has already been closed by a concurrent
     * reconciler (duplicate event, safety-poll race), returns null.
     *
     * @param array $fill Binance user-data order object (`o` envelope). Expected
     *                    keys: i (orderId), X (execution status), ap (avg price),
     *                    L (last fill price), z (cumulative filled qty).
     */
    public function reconcileFillFromStream(Position $position, array $fill, CloseReason $reason): ?Trade
    {
        $position->refresh();
        if ($position->status !== PositionStatus::Open) {
            return null;
        }

        $exchange = $this->exchange->resolve();

        $siblingId = $reason === CloseReason::StopLoss
            ? $position->tp_order_id
            : $position->sl_order_id;
        if ($siblingId) {
            try {
                // sl_order_id / tp_order_id hold algoIds (Binance migrated brackets
                // to /fapi/v1/algoOrder on 2025-12-09), so cancel via the algo path.
                $exchange->cancelAlgoOrder($position->symbol, $siblingId);
            } catch (\Throwable $e) {
                Log::warning('Failed to cancel sibling bracket after fill', [
                    'symbol' => $position->symbol,
                    'sibling_order_id' => $siblingId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $exitPrice = (float) ($fill['ap'] ?? $fill['L'] ?? 0);
        if ($exitPrice <= 0) {
            $exitPrice = $reason === CloseReason::StopLoss
                ? (float) $position->stop_loss_price
                : (float) $position->take_profit_price;
        }

        $orderId = (string) ($fill['i'] ?? '');

        Log::info('Reconciling position close from stream', [
            'symbol' => $position->symbol,
            'reason' => $reason->value,
            'orderId' => $orderId,
            'exit' => $exitPrice,
        ]);

        return $this->finalizeClose($position, $exitPrice, $orderId, $reason);
    }

    /**
     * Safety reconcile for a DB position that's no longer open on Binance.
     * Polls both bracket orders: if one is FILLED, reconcile with that reason.
     * Otherwise fall back to a manual close at the last known price.
     */
    public function reconcileMissingPosition(Position $position): ?Trade
    {
        $position->refresh();
        if ($position->status !== PositionStatus::Open) {
            return null;
        }

        // BotRun::safetyReconcile takes a getOpenPositions() snapshot then iterates
        // DB rows. A position opened between snapshot and iteration is absent from
        // the snapshot but legitimately open — don't close it as Manual.
        if ($position->opened_at && $position->opened_at->gt(now()->subSeconds(10))) {
            return null;
        }

        $reconciled = $this->reconcileFromBrackets($position, CloseReason::Manual);
        if ($reconciled !== null) {
            return $reconciled;
        }

        Log::warning('Position flat on exchange but no bracket fill found — closing as Manual', [
            'symbol' => $position->symbol,
        ]);
        $lastPrice = $position->current_price > 0
            ? (float) $position->current_price
            : (float) $position->entry_price;
        return $this->finalizeClose($position, $lastPrice, '', CloseReason::Manual);
    }

    /**
     * Probe both bracket orders' statuses. If one is FILLED, synthesize a
     * reconcile event for it and cancel the sibling. Returns null if neither
     * bracket is filled.
     */
    private function reconcileFromBrackets(Position $position, CloseReason $fallbackReason): ?Trade
    {
        $exchange = $this->exchange->resolve();

        foreach ([
            [$position->tp_order_id, CloseReason::TakeProfit],
            [$position->sl_order_id, CloseReason::StopLoss],
        ] as [$orderId, $reason]) {
            if (! $orderId) {
                continue;
            }
            try {
                // Brackets live on /fapi/v1/algoOrder; sl_order_id / tp_order_id
                // hold algoIds and must be queried via the algo endpoint.
                $status = $exchange->getAlgoOrderStatus($position->symbol, $orderId);
            } catch (\Throwable $e) {
                Log::warning('Bracket status check failed during reconcile', [
                    'symbol' => $position->symbol,
                    'orderId' => $orderId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            if (($status['status'] ?? '') === 'FILLED') {
                return $this->reconcileFillFromStream($position, [
                    'i' => $orderId,
                    'ap' => $status['avgPrice'] ?? 0,
                    'z' => $status['executedQty'] ?? 0,
                ], $reason);
            }
        }
        return null;
    }

    /**
     * Shared close-finalization: compute fees/PnL, update Position, create Trade.
     * Does NOT issue any exchange orders — caller is responsible for that.
     */
    private function finalizeClose(Position $position, float $exitPrice, string $exchangeOrderId, CloseReason $reason): Trade
    {
        $rates = $this->exchange->getCommissionRate($position->symbol);
        $takerRate = $rates['taker'];

        return DB::transaction(function () use ($position, $exitPrice, $exchangeOrderId, $reason, $takerRate) {
            $locked = Position::where('id', $position->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== PositionStatus::Open) {
                $existing = Trade::where('position_id', $position->id)
                    ->where('type', 'close')
                    ->latest('id')
                    ->first();
                if ($existing) {
                    return $existing;
                }
                throw new \RuntimeException("Position {$position->id} is not Open and no close Trade found");
            }

            $rawPnl = $this->calculatePnl($locked, $exitPrice);
            $pnlPct = $this->calculatePnlPct($locked, $exitPrice);

            $entryFee = $locked->total_entry_fee
                ?? ($locked->entry_price * $locked->quantity * $takerRate);
            $exitFee = $exitPrice * $locked->quantity * $takerRate;
            $totalFees = round($entryFee + $exitFee, 4);
            $pnl = round($rawPnl - $totalFees, 4);

            $status = match ($reason) {
                CloseReason::StopLoss => PositionStatus::StoppedOut,
                CloseReason::Expired => PositionStatus::Expired,
                default => PositionStatus::Closed,
            };

            $locked->update([
                'current_price' => $exitPrice,
                'unrealized_pnl' => 0,
                'status' => $status,
            ]);

            $fundingFee = $locked->funding_fee ?? 0;

            $trade = Trade::create([
                'position_id' => $locked->id,
                'symbol' => $locked->symbol,
                'side' => $locked->side,
                'type' => 'close',
                'entry_price' => $locked->entry_price,
                'exit_price' => $exitPrice,
                'quantity' => $locked->quantity,
                'pnl' => $pnl,
                'pnl_pct' => round($pnlPct, 4),
                'fees' => $totalFees,
                'funding_fee' => round($fundingFee, 4),
                'close_reason' => $reason,
                'exchange_order_id' => $exchangeOrderId,
                'is_dry_run' => $locked->is_dry_run,
            ]);

            Log::info('Position closed', [
                'symbol' => $locked->symbol,
                'side' => $locked->side,
                'reason' => $reason->value,
                'entry' => $locked->entry_price,
                'exit' => $exitPrice,
                'pnl' => $pnl,
                'pnl_pct' => round($pnlPct, 2) . '%',
            ]);

            $position->setRawAttributes($locked->getAttributes(), true);

            return $trade;
        });
    }

    /**
     * Binance error signatures that indicate "the position doesn't exist /
     * was already closed." Used to recognize when a manual or expiry close
     * loses a race against an autonomous SL/TP trigger on Binance.
     */
    private function looksLikeAlreadyFlat(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        // -2022: "ReduceOnly Order is rejected" (no position to reduce)
        // -4046: "No need to change position side"
        // -2019: "Margin is insufficient" can also surface in this path
        return str_contains($msg, '-2022')
            || str_contains($msg, 'ReduceOnly')
            || str_contains($msg, '-4046')
            || str_contains($msg, 'position amount')
            || str_contains($msg, 'is not valid');
    }

    public function addToPosition(Position $position, float $additionalUsdt): Position
    {
        if ($position->status !== PositionStatus::Open) {
            throw new \RuntimeException('Cannot add to a position that is not open');
        }
        if ($additionalUsdt <= 0) {
            throw new \RuntimeException('Additional amount must be greater than zero');
        }
        if ($this->isModeMismatch($position)) {
            throw new \RuntimeException(
                'Cannot add: dry_run setting does not match position origin. Close the position first.'
            );
        }

        $exchange = $this->exchange->resolve();

        $leverage = $position->leverage > 0 ? $position->leverage : 1;
        $requiredMargin = $additionalUsdt / $leverage;
        $accountData = $exchange->getAccountData();

        if ($accountData['availableBalance'] < $requiredMargin) {
            throw new \RuntimeException(
                "Insufficient balance: need \${$requiredMargin} margin, have \${$accountData['availableBalance']} available"
            );
        }

        $currentPrice = $exchange->getPrice($position->symbol);
        $additionalQty = $exchange->calculateQuantity($position->symbol, $additionalUsdt, $currentPrice);

        if ($additionalQty <= 0) {
            throw new \RuntimeException('Amount too small — calculated quantity is zero');
        }

        $order = $this->placeEntryOrder($exchange, $position->symbol, $additionalQty, $position->side);

        $fillPrice = $order['price'] > 0 ? $order['price'] : $currentPrice;
        $fillQty = $order['quantity'];

        $oldQty = $position->quantity;
        $totalQty = $oldQty + $fillQty;
        $newAvgEntry = round(($position->entry_price * $oldQty + $fillPrice * $fillQty) / $totalQty, 8);
        $totalUsdt = round($position->position_size_usdt + $additionalUsdt, 4);

        $rates = $exchange->getCommissionRate($position->symbol);
        $addFeeRate = ($order['entry_type'] ?? 'MARKET') === 'LIMIT_MAKER'
            ? $rates['maker']
            : $rates['taker'];
        $addFee = $fillPrice * $fillQty * $addFeeRate;
        $oldEntryFee = $position->total_entry_fee
            ?? ($position->entry_price * $position->quantity * $rates['taker']);
        $totalEntryFee = round($oldEntryFee + $addFee, 8);

        $this->cancelBrackets($exchange, $position);
        try {
            $slTp = $this->placeBrackets($exchange, $position->symbol, $newAvgEntry, $totalQty, $position->side);
        } catch (\Throwable $e) {
            Log::error('Failed to replace SL/TP after add — closing position for safety', [
                'symbol' => $position->symbol,
                'error' => $e->getMessage(),
            ]);

            // The add-leg market order already fired, so the exchange holds a larger
            // position with no active brackets. In live mode, checkPosition no longer
            // price-triggers SL/TP, so leaving this position open means it runs uncapped
            // until expiry. Close the whole thing.
            try {
                $exchange->cancelOrders($position->symbol);
            } catch (\Throwable $cancelErr) {
                Log::warning('Failed to cancel stray orders after bracket re-place failure', [
                    'symbol' => $position->symbol,
                    'error' => $cancelErr->getMessage(),
                ]);
            }

            $position->update([
                'entry_price' => $newAvgEntry,
                'quantity' => $totalQty,
                'position_size_usdt' => $totalUsdt,
                'stop_loss_price' => 0,
                'take_profit_price' => 0,
                'sl_order_id' => null,
                'tp_order_id' => null,
                'total_entry_fee' => $totalEntryFee,
            ]);

            try {
                $this->closePosition($position, reason: CloseReason::Manual);
            } catch (\Throwable $safetyCloseErr) {
                Log::error('CRITICAL: safety close after bracket failure also failed', [
                    'symbol' => $position->symbol,
                    'bracket_error' => $e->getMessage(),
                    'close_error' => $safetyCloseErr->getMessage(),
                ]);
                throw new \RuntimeException(
                    'Failed to re-place brackets after add AND safety close also failed — position may be unprotected: '
                    . $e->getMessage() . ' | close: ' . $safetyCloseErr->getMessage()
                );
            }

            throw new \RuntimeException(
                'Failed to re-place brackets after add; position was closed for safety: ' . $e->getMessage()
            );
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
        if ($this->isModeMismatch($position)) {
            throw new \RuntimeException(
                'Cannot reverse: dry_run setting does not match position origin. Close the position first.'
            );
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

        $exchange = $this->exchange->resolve();

        try {
            $price = $exchange->getPrice($symbol);
            $requiredMargin = $positionSizeUsdt / $leverage;
            $accountData = $exchange->getAccountData();
            if ($accountData['availableBalance'] < $requiredMargin) {
                Log::warning('Insufficient balance for reverse leg', ['symbol' => $symbol]);
                return ['trade' => $trade, 'position' => null];
            }

            $exchange->setMarginType($symbol, 'ISOLATED');
            $exchange->setLeverage($symbol, $leverage);
            $quantity = $exchange->calculateQuantity($symbol, $positionSizeUsdt, $price);
            if ($quantity <= 0) {
                return ['trade' => $trade, 'position' => null];
            }

            $order = $this->placeEntryOrder($exchange, $symbol, $quantity, $newDirection);
            $entryPrice = $order['price'] > 0 ? $order['price'] : $price;

            try {
                $slTp = $this->placeBrackets($exchange, $symbol, $entryPrice, $order['quantity'], $newDirection);
            } catch (\Throwable $e) {
                Log::error('Reverse: failed to set SL/TP — closing for safety', [
                    'symbol' => $symbol, 'error' => $e->getMessage(),
                ]);
                try {
                    $exchange->cancelOrders($symbol);
                } catch (\Throwable $cancelErr) {
                    Log::warning('Reverse: failed to cancel stray brackets', [
                        'symbol' => $symbol, 'error' => $cancelErr->getMessage(),
                    ]);
                }
                try {
                    $newDirection === 'LONG'
                        ? $exchange->closeLong($symbol, $order['quantity'])
                        : $exchange->closeShort($symbol, $order['quantity']);
                } catch (\Throwable $closeErr) {
                    Log::error('CRITICAL: Failed to close unprotected reversed position', [
                        'symbol' => $symbol, 'error' => $closeErr->getMessage(),
                    ]);
                }
                return ['trade' => $trade, 'position' => null];
            }

            $rates = $exchange->getCommissionRate($symbol);
            $entryFeeRate = ($order['entry_type'] ?? 'MARKET') === 'LIMIT_MAKER'
                ? $rates['maker']
                : $rates['taker'];
            $initialEntryFee = round($entryPrice * $order['quantity'] * $entryFeeRate, 8);

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
    private function placeBrackets(ExchangeInterface $exchange, string $symbol, float $entryPrice, float $quantity, string $side = 'SHORT'): array
    {
        $slTp = $this->calculateSlTp($entryPrice, $side);

        $slOrderId = null;
        $tpOrderId = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                if ($slOrderId === null) {
                    $slResult = $exchange->setStopLoss($symbol, $slTp['sl'], $quantity, $side);
                    $slOrderId = $slResult['orderId'] ?? null;
                }
                if ($tpOrderId === null) {
                    $tpResult = $exchange->setTakeProfit($symbol, $slTp['tp'], $quantity, $side);
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

    private function cancelBrackets(ExchangeInterface $exchange, Position $position): void
    {
        if (! $position->sl_order_id && ! $position->tp_order_id) {
            try {
                $exchange->cancelOrders($position->symbol);
            } catch (\Throwable $e) {
                Log::warning('Failed to cancel orders on close (legacy fallback)', [
                    'symbol' => $position->symbol, 'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        // sl_order_id / tp_order_id are algoIds (Binance migrated STOP_MARKET /
        // TAKE_PROFIT_MARKET off /fapi/v1/order onto /fapi/v1/algoOrder), so use
        // the algo cancel path. Regular cancelOrder would 400 with -2013.
        if ($position->sl_order_id) {
            try {
                $exchange->cancelAlgoOrder($position->symbol, $position->sl_order_id);
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
                $exchange->cancelAlgoOrder($position->symbol, $position->tp_order_id);
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

    /**
     * Returns true if the current dry_run setting disagrees with the origin mode
     * of this position. The ExchangeDispatcher routes to live or dry based on the
     * current setting; acting on a mismatched position would either close a real
     * Binance position via DryRunExchange or vice-versa. Callers must refuse.
     */
    private function isModeMismatch(Position $position): bool
    {
        return $position->is_dry_run !== (bool) Settings::get('dry_run');
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

    /**
     * Place an entry order. For SHORTs (when `use_post_only_entry` is on), tries a
     * post-only LIMIT at best ask first and falls back to MARKET on reject, timeout,
     * or partial fill. For LONGs, always uses MARKET.
     *
     * @return array{orderId: string, price: float, quantity: float, entry_type: string}
     */
    private function placeEntryOrder(ExchangeInterface $exchange, string $symbol, float $quantity, string $side): array
    {
        $usePostOnly = (bool) Settings::get('use_post_only_entry');

        if ($side !== 'SHORT' || ! $usePostOnly) {
            $order = $side === 'LONG'
                ? $exchange->openLong($symbol, $quantity)
                : $exchange->openShort($symbol, $quantity);

            return [
                'orderId' => $order['orderId'],
                'price' => (float) $order['price'],
                'quantity' => (float) $order['quantity'],
                'entry_type' => 'MARKET',
            ];
        }

        try {
            $book = $exchange->getOrderBookTop($symbol);
            $limitPrice = (float) ($book['ask'] ?? 0);
            if ($limitPrice <= 0) {
                throw new \RuntimeException('Invalid ask price from orderbook');
            }

            $limit = $exchange->openShortLimit($symbol, $quantity, $limitPrice, postOnly: true);

            if ($limit['status'] === 'FILLED') {
                Log::info('Entry filled as LIMIT_MAKER', [
                    'symbol' => $symbol,
                    'price' => $limit['price'],
                    'qty' => $limit['quantity'],
                    'orderId' => $limit['orderId'],
                ]);
                return [
                    'orderId' => (string) $limit['orderId'],
                    'price' => (float) $limit['price'],
                    'quantity' => (float) $limit['quantity'],
                    'entry_type' => 'LIMIT_MAKER',
                ];
            }

            $timeout = max(1, (int) Settings::get('limit_order_timeout_seconds') ?: 3);
            $deadline = microtime(true) + $timeout;
            $lastStatus = null;

            while (microtime(true) < $deadline) {
                usleep(500_000);
                $lastStatus = $exchange->getOrderStatus($symbol, $limit['orderId']);
                if ($lastStatus['status'] === 'FILLED') {
                    Log::info('Entry filled as LIMIT_MAKER (polled)', [
                        'symbol' => $symbol,
                        'price' => $lastStatus['avgPrice'],
                        'qty' => $lastStatus['executedQty'],
                        'orderId' => $limit['orderId'],
                    ]);
                    return [
                        'orderId' => (string) $limit['orderId'],
                        'price' => (float) $lastStatus['avgPrice'],
                        'quantity' => (float) $lastStatus['executedQty'],
                        'entry_type' => 'LIMIT_MAKER',
                    ];
                }
            }

            try {
                $exchange->cancelOrder($symbol, (string) $limit['orderId']);
            } catch (\Throwable $e) {
                Log::warning('Failed to cancel LIMIT on timeout', [
                    'symbol' => $symbol,
                    'orderId' => $limit['orderId'],
                    'error' => $e->getMessage(),
                ]);
            }

            $final = null;
            try {
                $final = $exchange->getOrderStatus($symbol, (string) $limit['orderId']);
            } catch (\Throwable) {
                // use $lastStatus if final query fails
            }
            $filled = (float) ($final['executedQty'] ?? $lastStatus['executedQty'] ?? 0);

            if ($filled >= $quantity) {
                return [
                    'orderId' => (string) $limit['orderId'],
                    'price' => (float) ($final['avgPrice'] ?? $lastStatus['avgPrice'] ?? $limitPrice),
                    'quantity' => $filled,
                    'entry_type' => 'LIMIT_MAKER',
                ];
            }

            $remaining = $quantity - $filled;
            Log::info('LIMIT timeout, falling back to MARKET', [
                'symbol' => $symbol,
                'filled' => $filled,
                'remaining' => $remaining,
            ]);

            $market = $exchange->openShort($symbol, $remaining);

            if ($filled > 0) {
                $limitFillPrice = (float) ($final['avgPrice'] ?? $lastStatus['avgPrice'] ?? $limitPrice);
                $totalQty = $filled + (float) $market['quantity'];
                $avgPrice = ($limitFillPrice * $filled + (float) $market['price'] * (float) $market['quantity']) / $totalQty;
                return [
                    'orderId' => (string) $market['orderId'],
                    'price' => round($avgPrice, 8),
                    'quantity' => $totalQty,
                    'entry_type' => 'MIXED',
                ];
            }

            return [
                'orderId' => (string) $market['orderId'],
                'price' => (float) $market['price'],
                'quantity' => (float) $market['quantity'],
                'entry_type' => 'MARKET_FALLBACK',
            ];
        } catch (\Throwable $e) {
            Log::info('Post-only path unavailable, using MARKET', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            $order = $exchange->openShort($symbol, $quantity);
            return [
                'orderId' => (string) $order['orderId'],
                'price' => (float) $order['price'],
                'quantity' => (float) $order['quantity'],
                'entry_type' => 'MARKET_FALLBACK',
            ];
        }
    }
}
