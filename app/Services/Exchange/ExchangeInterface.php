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
     * Calculate the quantity of contracts for a given USDT amount.
     */
    public function calculateQuantity(string $symbol, float $usdtAmount, float $price): float;

    /**
     * Check if a symbol is currently tradable on the exchange.
     */
    public function isTradable(string $symbol): bool;
}
