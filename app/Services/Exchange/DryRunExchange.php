<?php

namespace App\Services\Exchange;

use App\Enums\PositionStatus;
use App\Models\Position;
use App\Services\Settings;
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

    public function openShort(string $symbol, float $quantity): array
    {
        $price = $this->getPrice($symbol);
        $orderId = 'dry_' . uniqid();

        Log::info("[DRY RUN] Open short", [
            'symbol' => $symbol,
            'quantity' => $quantity,
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
        $price = $this->getPrice($symbol);
        $orderId = 'dry_' . uniqid();

        Log::info("[DRY RUN] Close short", [
            'symbol' => $symbol,
            'quantity' => $quantity,
            'price' => $price,
            'orderId' => $orderId,
        ]);

        return [
            'orderId' => $orderId,
            'price' => $price,
            'quantity' => $quantity,
        ];
    }

    public function setStopLoss(string $symbol, float $stopPrice, float $quantity): array
    {
        Log::info("[DRY RUN] Set stop-loss", [
            'symbol' => $symbol,
            'stopPrice' => $stopPrice,
            'quantity' => $quantity,
        ]);

        return ['orderId' => 'dry_sl_' . uniqid()];
    }

    public function setTakeProfit(string $symbol, float $takeProfitPrice, float $quantity): array
    {
        Log::info("[DRY RUN] Set take-profit", [
            'symbol' => $symbol,
            'takeProfitPrice' => $takeProfitPrice,
            'quantity' => $quantity,
        ]);

        return ['orderId' => 'dry_tp_' . uniqid()];
    }

    public function getBalance(): float
    {
        $startingBalance = (float) Settings::get('starting_balance');

        $allocatedUsdt = Position::where('status', PositionStatus::Open)
            ->where('is_dry_run', true)
            ->sum('position_size_usdt');

        $realizedPnl = \App\Models\Trade::where('is_dry_run', true)->sum('pnl');

        return $startingBalance - $allocatedUsdt + $realizedPnl;
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

    public function calculateQuantity(string $symbol, float $usdtAmount, float $price): float
    {
        return $this->realExchange->calculateQuantity($symbol, $usdtAmount, $price);
    }
}
