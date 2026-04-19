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
        $price = $this->getPrice($symbol);
        $orderId = 'dry_' . uniqid('', true);

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
        $orderId = 'dry_' . uniqid('', true);

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

    public function openLong(string $symbol, float $quantity): array
    {
        $price = $this->getPrice($symbol);
        $orderId = 'dry_' . uniqid('', true);

        Log::info("[DRY RUN] Open long", [
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

    public function closeLong(string $symbol, float $quantity): array
    {
        $price = $this->getPrice($symbol);
        $orderId = 'dry_' . uniqid('', true);

        Log::info("[DRY RUN] Close long", [
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

    public function setStopLoss(string $symbol, float $stopPrice, float $quantity, string $side = 'SHORT'): array
    {
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
        Log::info("[DRY RUN] Set take-profit", [
            'symbol' => $symbol,
            'takeProfitPrice' => $takeProfitPrice,
            'quantity' => $quantity,
            'side' => $side,
        ]);

        return ['orderId' => 'dry_tp_' . uniqid('', true)];
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
        return $this->realExchange->calculateQuantity($symbol, $usdtAmount, $price);
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

        // Valid maker SELL at or above mark: simulate immediate fill at limit price.
        $status = 'FILLED';
        $avgPrice = $price;

        Cache::put("dry:order:{$orderId}", [
            'symbol' => $symbol,
            'status' => $status,
            'executedQty' => $quantity,
            'avgPrice' => $avgPrice,
            'origQty' => $quantity,
        ], 300);

        Log::info("[DRY RUN] Open short LIMIT", [
            'symbol' => $symbol,
            'quantity' => $quantity,
            'price' => $price,
            'postOnly' => $postOnly,
            'status' => $status,
            'orderId' => $orderId,
        ]);

        return [
            'orderId' => $orderId,
            'price' => $avgPrice,
            'quantity' => $quantity,
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
}
