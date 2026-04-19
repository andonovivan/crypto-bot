<?php

namespace App\Services\Exchange;

interface ExchangeInterface
{
    /**
     * Get all futures trading pairs with 24h ticker data.
     *
     * @return array<array{symbol: string, price: float, priceChangePct: float, volume: float, high: float, low: float}>
     */
    public function getFuturesTickers(): array;

    /**
     * Get the current price for a symbol.
     */
    public function getPrice(string $symbol): float;

    /**
     * Get prices for multiple symbols in a single operation.
     *
     * @param array<string> $symbols
     * @return array<string, float> Symbol => price map
     */
    public function getPrices(array $symbols): array;

    /**
     * Get kline/candlestick data.
     *
     * @return array<array{openTime: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function getKlines(string $symbol, string $interval = '1h', int $limit = 24): array;

    /**
     * Set leverage for a symbol.
     */
    public function setLeverage(string $symbol, int $leverage): bool;

    /**
     * Set margin type for a symbol ('ISOLATED' or 'CROSSED').
     * Idempotent: returns true if already set to the requested mode.
     */
    public function setMarginType(string $symbol, string $marginType): bool;

    /**
     * Open a short position (market order).
     *
     * @return array{orderId: string, price: float, quantity: float}
     */
    public function openShort(string $symbol, float $quantity): array;

    /**
     * Close a short position (market buy).
     *
     * @return array{orderId: string, price: float, quantity: float}
     */
    public function closeShort(string $symbol, float $quantity): array;

    /**
     * Open a long position (market buy).
     *
     * @return array{orderId: string, price: float, quantity: float}
     */
    public function openLong(string $symbol, float $quantity): array;

    /**
     * Close a long position (market sell).
     *
     * @return array{orderId: string, price: float, quantity: float}
     */
    public function closeLong(string $symbol, float $quantity): array;

    /**
     * Set a stop-loss order.
     *
     * @param string $side Position side: 'LONG' or 'SHORT'
     * @return array{orderId: string}
     */
    public function setStopLoss(string $symbol, float $stopPrice, float $quantity, string $side = 'SHORT'): array;

    /**
     * Set a take-profit order.
     *
     * @param string $side Position side: 'LONG' or 'SHORT'
     * @return array{orderId: string}
     */
    public function setTakeProfit(string $symbol, float $takeProfitPrice, float $quantity, string $side = 'SHORT'): array;

    /**
     * Get account balance in USDT (available balance).
     */
    public function getBalance(): float;

    /**
     * Get full account data matching Binance Futures account structure.
     *
     * @return array{walletBalance: float, availableBalance: float, unrealizedProfit: float, marginBalance: float, positionMargin: float, maintMargin: float}
     */
    public function getAccountData(): array;

    /**
     * Get commission rates for a symbol.
     *
     * @return array{maker: float, taker: float}
     */
    public function getCommissionRate(string $symbol): array;

    /**
     * Get open positions from the exchange.
     *
     * @return array<array{symbol: string, quantity: float, entryPrice: float, unrealizedPnl: float}>
     */
    public function getOpenPositions(): array;

    /**
     * Cancel all open orders for a symbol.
     */
    public function cancelOrders(string $symbol): bool;

    /**
     * Cancel a single order by its order ID.
     *
     * @return bool True if cancelled successfully (or already gone)
     */
    public function cancelOrder(string $symbol, string $orderId): bool;

    /**
     * Calculate the quantity of contracts for a given USDT amount.
     */
    public function calculateQuantity(string $symbol, float $usdtAmount, float $price): float;

    /**
     * Check if a symbol is currently tradable on the exchange.
     */
    public function isTradable(string $symbol): bool;

    /**
     * Get current funding rate info from premiumIndex.
     *
     * @param  string|null  $symbol  Specific symbol, or null for all symbols
     * @return array<string, array{fundingRate: float, nextFundingTime: int, markPrice: float}>
     */
    public function getFundingRates(?string $symbol = null): array;

    /**
     * Get the top of the orderbook (best bid/ask) for a symbol.
     *
     * @return array{bid: float, ask: float}
     */
    public function getOrderBookTop(string $symbol): array;

    /**
     * Open a short position via LIMIT order. Use $postOnly=true for maker-only (timeInForce=GTX).
     *
     * @return array{orderId: string, price: float, quantity: float, status: string}
     */
    public function openShortLimit(string $symbol, float $quantity, float $price, bool $postOnly = true): array;

    /**
     * Query the current status of an order.
     *
     * @return array{orderId: string, status: string, executedQty: float, avgPrice: float, origQty: float}
     */
    public function getOrderStatus(string $symbol, string $orderId): array;

    /**
     * Cancel a conditional (algo) order by its algoId. Conditional orders
     * (STOP_MARKET / TAKE_PROFIT_MARKET brackets) live on /fapi/v1/algoOrder
     * since 2025-12-09 and use a distinct ID space from regular orders.
     *
     * @return bool True if cancelled successfully (or already gone)
     */
    public function cancelAlgoOrder(string $symbol, string $algoId): bool;

    /**
     * Query the current status of a conditional (algo) order by its algoId.
     * Returned shape matches getOrderStatus so callers can treat both uniformly.
     *
     * @return array{orderId: string, status: string, executedQty: float, avgPrice: float, origQty: float}
     */
    public function getAlgoOrderStatus(string $symbol, string $algoId): array;

    /**
     * Create a user-data stream listenKey. Valid for 60 minutes; must be kept alive.
     */
    public function createListenKey(): string;

    /**
     * Keep an existing listenKey alive. Call every ~30 minutes.
     */
    public function keepAliveListenKey(): void;

    /**
     * Close the user-data listenKey (graceful shutdown).
     */
    public function closeListenKey(): void;

    /**
     * Resolve this exchange to a concrete (non-routing) implementation.
     * Use at the top of multi-step flows to pin all calls to a single
     * backend, avoiding mid-flight dry_run toggle route-splits.
     */
    public function resolve(): ExchangeInterface;

    /**
     * Fetch per-fill account trades for a symbol since a given timestamp.
     * Used to reconstruct actual fill prices when bracket orders were
     * cancelled externally (e.g. operator closed on Binance UI) and the
     * ws-user-data stream missed the close event.
     *
     * @param  int  $sinceMs  Milliseconds-since-epoch lower bound (inclusive).
     * @param  int  $limit    Max rows to return (Binance max = 1000).
     * @return array<array{id: int, orderId: string, side: string, positionSide: string, price: float, qty: float, quoteQty: float, realizedPnl: float, commission: float, commissionAsset: string, time: int}>
     */
    public function getUserTrades(string $symbol, int $sinceMs, int $limit = 500): array;

    /**
     * Return the maximum initial leverage allowed for this symbol at the
     * smallest notional tier. Used to clamp the user-configured leverage to
     * a value Binance won't reject with -4028 ("Leverage N is not valid").
     */
    public function getMaxLeverage(string $symbol): int;
}
