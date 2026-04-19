<?php

namespace App\Services\Exchange;

use App\Services\Settings;

/**
 * Routes ExchangeInterface calls to either BinanceExchange (live) or
 * DryRunExchange (simulated) based on the current `dry_run` setting,
 * checked on every call. This makes the Settings UI toggle effective
 * at the next bot cycle without requiring a container restart.
 *
 * CAUTION: flipping dry_run while positions are open will route their
 * closes to the newly-active exchange, which won't know about them.
 * Always close all open positions before switching modes.
 */
class ExchangeDispatcher implements ExchangeInterface
{
    public function __construct(
        private BinanceExchange $live,
        private DryRunExchange $dry,
    ) {}

    private function active(): ExchangeInterface
    {
        return (bool) Settings::get('dry_run') ? $this->dry : $this->live;
    }

    public function getFuturesTickers(): array
    {
        return $this->active()->getFuturesTickers();
    }

    public function getPrice(string $symbol): float
    {
        return $this->active()->getPrice($symbol);
    }

    public function getPrices(array $symbols): array
    {
        return $this->active()->getPrices($symbols);
    }

    public function getKlines(string $symbol, string $interval = '1h', int $limit = 24): array
    {
        return $this->active()->getKlines($symbol, $interval, $limit);
    }

    public function setLeverage(string $symbol, int $leverage): bool
    {
        return $this->active()->setLeverage($symbol, $leverage);
    }

    public function setMarginType(string $symbol, string $marginType): bool
    {
        return $this->active()->setMarginType($symbol, $marginType);
    }

    public function openShort(string $symbol, float $quantity): array
    {
        return $this->active()->openShort($symbol, $quantity);
    }

    public function closeShort(string $symbol, float $quantity): array
    {
        return $this->active()->closeShort($symbol, $quantity);
    }

    public function openLong(string $symbol, float $quantity): array
    {
        return $this->active()->openLong($symbol, $quantity);
    }

    public function closeLong(string $symbol, float $quantity): array
    {
        return $this->active()->closeLong($symbol, $quantity);
    }

    public function setStopLoss(string $symbol, float $stopPrice, float $quantity, string $side = 'SHORT'): array
    {
        return $this->active()->setStopLoss($symbol, $stopPrice, $quantity, $side);
    }

    public function setTakeProfit(string $symbol, float $takeProfitPrice, float $quantity, string $side = 'SHORT'): array
    {
        return $this->active()->setTakeProfit($symbol, $takeProfitPrice, $quantity, $side);
    }

    public function getBalance(): float
    {
        return $this->active()->getBalance();
    }

    public function getAccountData(): array
    {
        return $this->active()->getAccountData();
    }

    public function getCommissionRate(string $symbol): array
    {
        return $this->active()->getCommissionRate($symbol);
    }

    public function getOpenPositions(): array
    {
        return $this->active()->getOpenPositions();
    }

    public function cancelOrders(string $symbol): bool
    {
        return $this->active()->cancelOrders($symbol);
    }

    public function cancelOrder(string $symbol, string $orderId): bool
    {
        return $this->active()->cancelOrder($symbol, $orderId);
    }

    public function calculateQuantity(string $symbol, float $usdtAmount, float $price): float
    {
        return $this->active()->calculateQuantity($symbol, $usdtAmount, $price);
    }

    public function isTradable(string $symbol): bool
    {
        return $this->active()->isTradable($symbol);
    }

    public function getFundingRates(?string $symbol = null): array
    {
        return $this->active()->getFundingRates($symbol);
    }

    public function getOrderBookTop(string $symbol): array
    {
        return $this->active()->getOrderBookTop($symbol);
    }

    public function openShortLimit(string $symbol, float $quantity, float $price, bool $postOnly = true): array
    {
        return $this->active()->openShortLimit($symbol, $quantity, $price, $postOnly);
    }

    public function getOrderStatus(string $symbol, string $orderId): array
    {
        return $this->active()->getOrderStatus($symbol, $orderId);
    }

    public function cancelAlgoOrder(string $symbol, string $algoId): bool
    {
        return $this->active()->cancelAlgoOrder($symbol, $algoId);
    }

    public function getAlgoOrderStatus(string $symbol, string $algoId): array
    {
        return $this->active()->getAlgoOrderStatus($symbol, $algoId);
    }

    public function createListenKey(): string
    {
        return $this->active()->createListenKey();
    }

    public function keepAliveListenKey(): void
    {
        $this->active()->keepAliveListenKey();
    }

    public function closeListenKey(): void
    {
        $this->active()->closeListenKey();
    }

    public function resolve(): ExchangeInterface
    {
        return $this->active();
    }

    public function getUserTrades(string $symbol, int $sinceMs, int $limit = 500): array
    {
        return $this->active()->getUserTrades($symbol, $sinceMs, $limit);
    }

    public function getMaxLeverage(string $symbol): int
    {
        return $this->active()->getMaxLeverage($symbol);
    }
}
