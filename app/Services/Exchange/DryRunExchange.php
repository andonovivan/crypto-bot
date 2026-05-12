<?php

namespace App\Services\Exchange;

use App\Enums\PositionStatus;
use App\Models\Position;
use App\Services\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Simulated exchange for dry-run/paper trading.
 * Wraps a real exchange for market data but simulates order execution.
 * Tracks balance changes based on positions in the database.
 */
class DryRunExchange implements ExchangeInterface
{
    private ExchangeInterface $realExchange;

    public function __construct(ExchangeInterface $realExchange)
    {
        $this->realExchange = $realExchange;
    }

    public function getFuturesTickers(): array
    {
        return $this->realExchange->getFuturesTickers();
    }

    public function getPrice(string $symbol): float
    {
        return $this->realExchange->getPrice($symbol);
    }

    public function getPrices(array $symbols): array
    {
        return $this->realExchange->getPrices($symbols);
    }

    public function getKlines(string $symbol, string $interval = '1h', int $limit = 24): array
    {
        return $this->realExchange->getKlines($symbol, $interval, $limit);
    }

    public function isTradable(string $symbol): bool
    {
        return $this->realExchange->isTradable($symbol);
    }

    public function setLeverage(string $symbol, int $leverage): bool
    {
        Log::info("[DRY RUN] Set leverage", ['symbol' => $symbol, 'leverage' => $leverage]);
        return true;
    }

    public function setMarginType(string $symbol, string $marginType): bool
    {
        Log::info("[DRY RUN] Set margin type", ['symbol' => $symbol, 'marginType' => $marginType]);
        return true;
    }

    public function openShort(string $symbol, float $quantity): array
    {
        $mark = $this->getPrice($symbol);
        $price = $this->applyMarketSlippage($mark, 'SELL');
        $orderId = 'dry_' . uniqid('', true);

        Log::info("[DRY RUN] Open short", [
            'symbol' => $symbol,
            'quantity' => $quantity,
            'mark' => $mark,
            'price' => $price,
            'orderId' => $orderId,
        ]);

        return [
            'orderId' => $orderId,
            'price' => $price,
            'quantity' => $quantity,
        ];
    }

    public function closeShort(string $symbol, float $quantity): array
    {
        $mark = $this->getPrice($symbol);
        $price = $this->applyMarketSlippage($mark, 'BUY');
        $orderId = 'dry_' . uniqid('', true);

        Log::info("[DRY RUN] Close short", [
            'symbol' => $symbol,
            'quantity' => $quantity,
            'mark' => $mark,
            'price' => $price,
            'orderId' => $orderId,
        ]);

        return [
            'orderId' => $orderId,
            'price' => $price,
            'quantity' => $quantity,
        ];
    }

    public function openLong(string $symbol, float $quantity): array
    {
        $mark = $this->getPrice($symbol);
        $price = $this->applyMarketSlippage($mark, 'BUY');
        $orderId = 'dry_' . uniqid('', true);

        Log::info("[DRY RUN] Open long", [
            'symbol' => $symbol,
            'quantity' => $quantity,
            'mark' => $mark,
            'price' => $price,
            'orderId' => $orderId,
        ]);

        return [
            'orderId' => $orderId,
            'price' => $price,
            'quantity' => $quantity,
        ];
    }

    public function closeLong(string $symbol, float $quantity): array
    {
        $mark = $this->getPrice($symbol);
        $price = $this->applyMarketSlippage($mark, 'SELL');
        $orderId = 'dry_' . uniqid('', true);

        Log::info("[DRY RUN] Close long", [
            'symbol' => $symbol,
            'quantity' => $quantity,
            'mark' => $mark,
            'price' => $price,
            'orderId' => $orderId,
        ]);

        return [
            'orderId' => $orderId,
            'price' => $price,
            'quantity' => $quantity,
        ];
    }

    /**
     * Apply an adverse market-order slippage to the mark price.
     * SELL gets a worse (lower) fill, BUY gets a worse (higher) fill.
     * Configured via the `dry_run_market_slippage_bps` setting (basis points).
     */
    private function applyMarketSlippage(float $mark, string $direction): float
    {
        $bps = (float) Settings::get('dry_run_market_slippage_bps');
        if ($bps <= 0 || $mark <= 0) {
            return $mark;
        }

        $adjust = $bps / 10000.0;
        if ($direction === 'SELL') {
            return $mark * (1 - $adjust);
        }
        if ($direction === 'BUY') {
            return $mark * (1 + $adjust);
        }
        return $mark;
    }

    public function setStopLoss(string $symbol, float $stopPrice, float $quantity, string $side = 'SHORT'): array
    {
        $this->maybeFailBracket('stop_loss', $symbol);

        Log::info("[DRY RUN] Set stop-loss", [
            'symbol' => $symbol,
            'stopPrice' => $stopPrice,
            'quantity' => $quantity,
            'side' => $side,
        ]);

        return ['orderId' => 'dry_sl_' . uniqid('', true)];
    }

    public function setTakeProfit(string $symbol, float $takeProfitPrice, float $quantity, string $side = 'SHORT'): array
    {
        $this->maybeFailBracket('take_profit', $symbol);

        Log::info("[DRY RUN] Set take-profit", [
            'symbol' => $symbol,
            'takeProfitPrice' => $takeProfitPrice,
            'quantity' => $quantity,
            'side' => $side,
        ]);

        return ['orderId' => 'dry_tp_' . uniqid('', true)];
    }

    public function openTrailingStop(string $symbol, string $side, float $quantity, float $activationPrice, float $callbackRate): array
    {
        $this->maybeFailBracket('trailing_stop', $symbol);

        // No real Binance order in dry-run. The bot's TradingEngine::maybeTrailStop
        // re-implements the trigger semantics against the cached price stream and
        // closes the DB position when the trail fires. We just hand back a fake
        // orderId so the caller can store something in tp_order_id.
        Log::info("[DRY RUN] Open trailing stop", [
            'symbol' => $symbol,
            'side' => $side,
            'quantity' => $quantity,
            'activationPrice' => $activationPrice,
            'callbackRate' => $callbackRate,
        ]);

        return ['orderId' => 'dry_trail_' . uniqid('', true)];
    }

    /**
     * Randomly simulate a bracket-order placement failure (rate-limit blip,
     * lev-tier mismatch, position-mode quirk). On failure throws — the
     * TradingEngine::placeBrackets fail-safe catches this, cancels sibling
     * brackets, and force-closes the position. Exercises the same path live
     * money will hit ~1-3% of the time per bracket.
     */
    private function maybeFailBracket(string $kind, string $symbol): void
    {
        $rate = (float) Settings::get('dry_run_bracket_fail_rate');
        if ($rate <= 0) {
            return;
        }
        if ($rate > 1) $rate = 1;

        $roll = mt_rand() / mt_getrandmax();
        if ($roll < $rate) {
            Log::warning('[DRY RUN] Bracket placement failed (simulated)', [
                'kind' => $kind,
                'symbol' => $symbol,
                'rate' => $rate,
            ]);
            throw new \RuntimeException("Simulated {$kind} placement failure (dry-run)");
        }
    }

    public function getBalance(): float
    {
        return $this->getAccountData()['availableBalance'];
    }

    public function getAccountData(): array
    {
        $startingBalance = (float) Settings::get('starting_balance');
        $realizedPnl = \App\Models\Trade::where('is_dry_run', true)->sum('pnl');

        // Add all funding fees: closed trade funding + open position funding
        // (Binance settles funding to wallet in real-time, even while positions are open)
        $closedFunding = \App\Models\Trade::where('is_dry_run', true)->sum('funding_fee');
        $openFunding = Position::where('status', PositionStatus::Open)
            ->where('is_dry_run', true)
            ->sum('funding_fee');

        $walletBalance = $startingBalance + $realizedPnl + $closedFunding + $openFunding;

        // Margin = notional / leverage (not the full notional)
        $openPositions = Position::where('status', PositionStatus::Open)
            ->where('is_dry_run', true)
            ->get(['position_size_usdt', 'leverage', 'unrealized_pnl']);

        $positionMargin = 0.0;
        $unrealizedProfit = 0.0;
        foreach ($openPositions as $pos) {
            $leverage = $pos->leverage > 0 ? $pos->leverage : 1;
            $positionMargin += $pos->position_size_usdt / $leverage;
            $unrealizedProfit += $pos->unrealized_pnl ?? 0;
        }

        $marginBalance = $walletBalance + $unrealizedProfit;
        $availableBalance = $walletBalance - $positionMargin + $unrealizedProfit;

        return [
            'walletBalance' => round($walletBalance, 4),
            'availableBalance' => round($availableBalance, 4),
            'unrealizedProfit' => round($unrealizedProfit, 4),
            'marginBalance' => round($marginBalance, 4),
            'positionMargin' => round($positionMargin, 4),
            'maintMargin' => round($positionMargin * 0.4, 4),
        ];
    }

    public function getCommissionRate(string $symbol): array
    {
        $rate = (float) Settings::get('dry_run_fee_rate');

        return [
            'maker' => round($rate * 0.5, 8),
            'taker' => $rate,
        ];
    }

    public function getOpenPositions(): array
    {
        return Position::where('status', PositionStatus::Open)
            ->where('is_dry_run', true)
            ->get()
            ->map(fn (Position $p) => [
                'symbol' => $p->symbol,
                'quantity' => $p->quantity,
                'entryPrice' => $p->entry_price,
                'unrealizedPnl' => $p->unrealized_pnl ?? 0,
            ])
            ->toArray();
    }

    public function cancelOrders(string $symbol): bool
    {
        Log::info("[DRY RUN] Cancel orders", ['symbol' => $symbol]);
        return true;
    }

    public function cancelOrder(string $symbol, string $orderId): bool
    {
        Log::info("[DRY RUN] Cancel order", ['symbol' => $symbol, 'orderId' => $orderId]);
        return true;
    }

    public function calculateQuantity(string $symbol, float $usdtAmount, float $price): float
    {
        $quantity = $this->realExchange->calculateQuantity($symbol, $usdtAmount, $price);

        // Simulate Binance's MIN_NOTIONAL filter: reject orders below the per-symbol
        // minimum notional. The real exchange already enforces minQty via roundStepSize
        // (returns 0); this adds the orthogonal minNotional check that live Binance
        // applies. Returning 0 routes through TradingEngine's quantity<=0 path and
        // records a Failed position, exactly as a live -4164 rejection would.
        if ($quantity > 0 && $price > 0 && method_exists($this->realExchange, 'getExchangeInfo')) {
            try {
                $info = $this->realExchange->getExchangeInfo();
                $minNotional = (float) ($info[$symbol]['minNotional'] ?? 0);
                if ($minNotional > 0 && ($quantity * $price) < $minNotional) {
                    Log::info('[DRY RUN] Below minNotional, returning quantity=0', [
                        'symbol' => $symbol,
                        'notional' => $quantity * $price,
                        'minNotional' => $minNotional,
                    ]);
                    return 0.0;
                }
            } catch (\Throwable $e) {
                // exchangeInfo unavailable — fall through, behave as before
            }
        }

        return $quantity;
    }

    public function getFundingRates(?string $symbol = null): array
    {
        return $this->realExchange->getFundingRates($symbol);
    }

    public function getOrderBookTop(string $symbol): array
    {
        $price = $this->getPrice($symbol);

        return [
            'bid' => round($price * 0.9999, 8),
            'ask' => round($price * 1.0001, 8),
        ];
    }

    public function openShortLimit(string $symbol, float $quantity, float $price, bool $postOnly = true): array
    {
        $mark = $this->getPrice($symbol);

        // Post-only SELL rejects if price would immediately cross (price at or below mark).
        // Binance returns error -2021 / -2010 in this case.
        if ($postOnly && $price < $mark) {
            Log::warning("[DRY RUN] Post-only SELL rejected (would cross)", [
                'symbol' => $symbol,
                'limit_price' => $price,
                'mark' => $mark,
            ]);
            throw new \RuntimeException("Post-only order would cross (simulated -2021)");
        }

        $orderId = 'dry_lim_' . uniqid('', true);

        // Simulate the real-world post-only fill rate. In live, a non-crossing
        // maker order has a probability of getting filled before its timeout —
        // it depends on queue position, price movement, and liquidity. Default
        // 0.6 (60%) approximates the empirical fill rate observed on Binance
        // Futures for tight post-only limits.
        $fillRate = (float) Settings::get('dry_run_maker_fill_rate');
        if ($fillRate < 0) $fillRate = 0;
        if ($fillRate > 1) $fillRate = 1;

        $roll = mt_rand() / mt_getrandmax();
        $willFill = $roll < $fillRate;

        if ($willFill) {
            // Maker SELL fills at the limit price.
            $status = 'FILLED';
            $avgPrice = $price;
            $executedQty = $quantity;
        } else {
            // Order rests on the book without filling — the bot's polling loop
            // will see NEW for `limit_order_timeout_seconds`, then cancel and
            // fall back to MARKET via TradingEngine::placeEntryOrder.
            $status = 'NEW';
            $avgPrice = 0.0;
            $executedQty = 0.0;
        }

        Cache::put("dry:order:{$orderId}", [
            'symbol' => $symbol,
            'status' => $status,
            'executedQty' => $executedQty,
            'avgPrice' => $avgPrice,
            'origQty' => $quantity,
        ], 300);

        Log::info("[DRY RUN] Open short LIMIT", [
            'symbol' => $symbol,
            'quantity' => $quantity,
            'price' => $price,
            'postOnly' => $postOnly,
            'status' => $status,
            'fill_rate' => $fillRate,
            'orderId' => $orderId,
        ]);

        return [
            'orderId' => $orderId,
            'price' => $avgPrice,
            'quantity' => $executedQty,
            'status' => $status,
        ];
    }

    public function getOrderStatus(string $symbol, string $orderId): array
    {
        $cached = Cache::get("dry:order:{$orderId}");

        if ($cached === null) {
            return [
                'orderId' => $orderId,
                'status' => 'UNKNOWN',
                'executedQty' => 0.0,
                'avgPrice' => 0.0,
                'origQty' => 0.0,
            ];
        }

        return [
            'orderId' => $orderId,
            'status' => $cached['status'],
            'executedQty' => (float) $cached['executedQty'],
            'avgPrice' => (float) $cached['avgPrice'],
            'origQty' => (float) $cached['origQty'],
        ];
    }

    public function cancelAlgoOrder(string $symbol, string $algoId): bool
    {
        Log::info("[DRY RUN] Cancel algo order", ['symbol' => $symbol, 'algoId' => $algoId]);
        return true;
    }

    public function getAlgoOrderStatus(string $symbol, string $algoId): array
    {
        // Dry-run brackets never rest on a real exchange — checkPosition() triggers
        // closes via price comparison, so reconciliation paths that would query
        // algo status never execute in dry-run. Return UNKNOWN as a safe default.
        return [
            'orderId' => $algoId,
            'status' => 'UNKNOWN',
            'executedQty' => 0.0,
            'avgPrice' => 0.0,
            'origQty' => 0.0,
        ];
    }

    public function createListenKey(): string
    {
        return 'dry_listenkey_' . uniqid('', true);
    }

    public function keepAliveListenKey(): void
    {
        // no-op
    }

    public function closeListenKey(): void
    {
        // no-op
    }

    public function resolve(): ExchangeInterface
    {
        return $this;
    }

    public function getUserTrades(string $symbol, int $sinceMs, int $limit = 500): array
    {
        return [];
    }

    public function getMaxLeverage(string $symbol): int
    {
        return $this->realExchange->getMaxLeverage($symbol);
    }
}
