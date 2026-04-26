<?php

namespace App\Services\Exchange;

use App\Enums\PositionStatus;
use App\Models\Position;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Offline-replay exchange for backtesting. Loads kline_history for a date
 * range into memory, then services price / ticker / kline queries against
 * a movable clock. Order simulation mirrors DryRunExchange: fills at the
 * current replay price, balance computed from DB Position/Trade rows.
 *
 * All positions/trades written during a backtest use is_dry_run=true so
 * the rest of the stack (TradingEngine, Settings, dashboards) sees the
 * same shape as a live dry-run session.
 */
class HistoricalReplayExchange implements ExchangeInterface
{
    /** @var array<string, array<int, array{o: float, h: float, l: float, c: float, v: float, qv: float}>> */
    private array $bars15m = [];

    /** @var array<string, array<int, array{o: float, h: float, l: float, c: float, v: float, qv: float}>> */
    private array $bars1h = [];

    /** @var array<string, int[]> sorted open_times per symbol */
    private array $openTimes15m = [];

    /** @var array<string, int[]> */
    private array $openTimes1h = [];

    /** @var int Current replay time, unix milliseconds */
    private int $clockMs = 0;

    /**
     * @var array<string, float> Symbol-scoped probe price overrides. When tickPosition
     * walks intra-bar high→low→close probes, we need SL/TP fills to execute AT the
     * probe price, not at the 15m bar close (which would systematically exaggerate
     * SL losses on red bars where high precedes close).
     */
    private array $probePrices = [];

    /** When true, getAccountData() reports walletBalance=startingBalance for position
     * sizing, so compounding doesn't distort per-trade performance evaluation. The
     * realized P&L still accumulates in Trade.pnl for final summary. */
    private bool $fixedSizing = false;

    private int $fromMs;
    private int $toMs;

    private ?BinanceExchange $realExchange = null;

    public function __construct(int $fromMs, int $toMs, ?array $symbolFilter = null)
    {
        $this->fromMs = $fromMs;
        $this->toMs = $toMs;
        $this->clockMs = $fromMs;
        $this->loadKlines($symbolFilter);
    }

    public function setClock(int $unixMs): void
    {
        $this->clockMs = $unixMs;
    }

    public function getClock(): int
    {
        return $this->clockMs;
    }

    public function setProbePrice(string $symbol, float $price): void
    {
        $this->probePrices[$symbol] = $price;
    }

    public function clearProbePrice(string $symbol): void
    {
        unset($this->probePrices[$symbol]);
    }

    public function setFixedSizing(bool $enabled): void
    {
        $this->fixedSizing = $enabled;
    }

    /**
     * Return the 15m bar whose open_time == clockMs (exact alignment). Used by
     * the backtest driver to simulate intra-bar SL/TP triggers via the bar's
     * high and low, which a close-only check would miss on volatile pump/dump
     * coins that wick 2–3% inside a single 15m candle.
     *
     * @return array{o: float, h: float, l: float, c: float}|null
     */
    public function getCurrentBar15m(string $symbol): ?array
    {
        if (! isset($this->bars15m[$symbol][$this->clockMs])) {
            return null;
        }
        $b = $this->bars15m[$symbol][$this->clockMs];
        return ['o' => $b['o'], 'h' => $b['h'], 'l' => $b['l'], 'c' => $b['c']];
    }

    /**
     * @return string[] symbols that have at least one 15m bar in the loaded range
     */
    public function loadedSymbols(): array
    {
        return array_keys($this->bars15m);
    }

    private function loadKlines(?array $symbolFilter): void
    {
        // Pull back enough lead-in candles before $fromMs so the scanner can
        // compute EMAs and 24h rolling aggregates on the very first tick.
        // 96 × 15m = 24h (for 24h change / volume); 26 × 1h for HTF EMA21.
        $leadIn15m = 200 * 15 * 60 * 1000;
        $leadIn1h = 50 * 60 * 60 * 1000;

        $q15 = DB::table('kline_history')
            ->select(['symbol', 'open_time', 'open', 'high', 'low', 'close', 'volume', 'quote_volume'])
            ->where('interval', '15m')
            ->whereBetween('open_time', [$this->fromMs - $leadIn15m, $this->toMs]);
        if ($symbolFilter) {
            $q15->whereIn('symbol', $symbolFilter);
        }

        foreach ($q15->orderBy('symbol')->orderBy('open_time')->cursor() as $row) {
            $s = $row->symbol;
            $this->bars15m[$s][$row->open_time] = [
                'o' => (float) $row->open,
                'h' => (float) $row->high,
                'l' => (float) $row->low,
                'c' => (float) $row->close,
                'v' => (float) $row->volume,
                'qv' => (float) $row->quote_volume,
            ];
            $this->openTimes15m[$s][] = (int) $row->open_time;
        }

        $q1h = DB::table('kline_history')
            ->select(['symbol', 'open_time', 'open', 'high', 'low', 'close', 'volume', 'quote_volume'])
            ->where('interval', '1h')
            ->whereBetween('open_time', [$this->fromMs - $leadIn1h, $this->toMs]);
        if ($symbolFilter) {
            $q1h->whereIn('symbol', $symbolFilter);
        }

        foreach ($q1h->orderBy('symbol')->orderBy('open_time')->cursor() as $row) {
            $s = $row->symbol;
            $this->bars1h[$s][$row->open_time] = [
                'o' => (float) $row->open,
                'h' => (float) $row->high,
                'l' => (float) $row->low,
                'c' => (float) $row->close,
                'v' => (float) $row->volume,
                'qv' => (float) $row->quote_volume,
            ];
            $this->openTimes1h[$s][] = (int) $row->open_time;
        }
    }

    /**
     * Return the index of the latest bar whose open_time <= clock for this symbol/interval.
     * Uses binary search on the pre-sorted open_times array.
     */
    private function indexAtClock(string $symbol, string $interval): ?int
    {
        $times = $interval === '15m'
            ? ($this->openTimes15m[$symbol] ?? null)
            : ($this->openTimes1h[$symbol] ?? null);
        if (! $times) {
            return null;
        }
        $lo = 0;
        $hi = count($times) - 1;
        $best = null;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($times[$mid] <= $this->clockMs) {
                $best = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        return $best;
    }

    public function getFuturesTickers(): array
    {
        $out = [];
        foreach ($this->bars15m as $symbol => $_) {
            $idx = $this->indexAtClock($symbol, '15m');
            if ($idx === null) {
                continue;
            }

            $times = $this->openTimes15m[$symbol];
            $currentBar = $this->bars15m[$symbol][$times[$idx]];

            // 24h change: compare current close to close 96 bars (24h) ago.
            $backIdx = $idx - 96;
            if ($backIdx < 0) {
                continue;
            }
            $backBar = $this->bars15m[$symbol][$times[$backIdx]];
            $priorClose = $backBar['c'];
            $priceChangePct = $priorClose > 0
                ? (($currentBar['c'] - $priorClose) / $priorClose) * 100
                : 0;

            // 24h quote volume: sum of last 96 bars inclusive of current.
            $vol = 0.0;
            for ($i = max(0, $idx - 95); $i <= $idx; $i++) {
                $vol += $this->bars15m[$symbol][$times[$i]]['qv'];
            }

            $out[] = [
                'symbol' => $symbol,
                'price' => $currentBar['c'],
                'priceChangePct' => $priceChangePct,
                'volume' => $vol,
                'high' => $currentBar['h'],
                'low' => $currentBar['l'],
            ];
        }
        return $out;
    }

    public function getPrice(string $symbol): float
    {
        if (isset($this->probePrices[$symbol])) {
            return $this->probePrices[$symbol];
        }
        $idx = $this->indexAtClock($symbol, '15m');
        if ($idx === null) {
            return 0.0;
        }
        $times = $this->openTimes15m[$symbol];
        return $this->bars15m[$symbol][$times[$idx]]['c'];
    }

    public function getPrices(array $symbols): array
    {
        $out = [];
        foreach ($symbols as $s) {
            $out[$s] = $this->getPrice($s);
        }
        return $out;
    }

    public function getKlines(string $symbol, string $interval = '1h', int $limit = 24): array
    {
        $idx = $this->indexAtClock($symbol, $interval);
        if ($idx === null) {
            return [];
        }
        $times = $interval === '15m'
            ? $this->openTimes15m[$symbol]
            : $this->openTimes1h[$symbol];
        $bars = $interval === '15m' ? $this->bars15m[$symbol] : $this->bars1h[$symbol];

        $start = max(0, $idx - $limit + 1);
        $out = [];
        for ($i = $start; $i <= $idx; $i++) {
            $t = $times[$i];
            $b = $bars[$t];
            $out[] = [
                'openTime' => $t,
                'open' => $b['o'],
                'high' => $b['h'],
                'low' => $b['l'],
                'close' => $b['c'],
                'volume' => $b['v'],
            ];
        }
        return $out;
    }

    public function isTradable(string $symbol): bool
    {
        return isset($this->bars15m[$symbol]);
    }

    public function setLeverage(string $symbol, int $leverage): bool
    {
        return true;
    }

    public function setMarginType(string $symbol, string $marginType): bool
    {
        return true;
    }

    public function openShort(string $symbol, float $quantity): array
    {
        $price = $this->getPrice($symbol);
        return [
            'orderId' => 'bt_' . uniqid('', true),
            'price' => $price,
            'quantity' => $quantity,
        ];
    }

    public function closeShort(string $symbol, float $quantity): array
    {
        $price = $this->getPrice($symbol);
        return [
            'orderId' => 'bt_' . uniqid('', true),
            'price' => $price,
            'quantity' => $quantity,
        ];
    }

    public function openLong(string $symbol, float $quantity): array
    {
        $price = $this->getPrice($symbol);
        return [
            'orderId' => 'bt_' . uniqid('', true),
            'price' => $price,
            'quantity' => $quantity,
        ];
    }

    public function closeLong(string $symbol, float $quantity): array
    {
        $price = $this->getPrice($symbol);
        return [
            'orderId' => 'bt_' . uniqid('', true),
            'price' => $price,
            'quantity' => $quantity,
        ];
    }

    public function setStopLoss(string $symbol, float $stopPrice, float $quantity, string $side = 'SHORT'): array
    {
        return ['orderId' => 'bt_sl_' . uniqid('', true)];
    }

    public function setTakeProfit(string $symbol, float $takeProfitPrice, float $quantity, string $side = 'SHORT'): array
    {
        return ['orderId' => 'bt_tp_' . uniqid('', true)];
    }

    public function openTrailingStop(string $symbol, string $side, float $quantity, float $activationPrice, float $callbackRate): array
    {
        // Backtest: no order matching engine. The bot's maybeTrailStop()
        // simulates trigger semantics against probe prices. Just stash a fake id.
        return ['orderId' => 'bt_trail_' . uniqid('', true)];
    }

    public function getBalance(): float
    {
        return $this->getAccountData()['availableBalance'];
    }

    public function getAccountData(): array
    {
        $startingBalance = (float) Settings::get('starting_balance');
        $realizedPnl = \App\Models\Trade::where('is_dry_run', true)->sum('pnl');
        $closedFunding = \App\Models\Trade::where('is_dry_run', true)->sum('funding_fee');
        $openFunding = Position::where('status', PositionStatus::Open)
            ->where('is_dry_run', true)
            ->sum('funding_fee');

        $walletBalance = $this->fixedSizing
            ? $startingBalance
            : $startingBalance + $realizedPnl + $closedFunding + $openFunding;

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
        return true;
    }

    public function cancelOrder(string $symbol, string $orderId): bool
    {
        return true;
    }

    public function calculateQuantity(string $symbol, float $usdtAmount, float $price): float
    {
        if ($price <= 0) {
            return 0.0;
        }
        $qty = $usdtAmount / $price;
        // Generic 6-decimal rounding — LOT_SIZE isn't known offline. The scanner
        // wouldn't have accepted a symbol in live that can't hit this precision,
        // so this is close enough for backtest realism.
        return round($qty, 6);
    }

    public function getFundingRates(?string $symbol = null): array
    {
        // Historical funding data isn't loaded in v1 — FundingSettlementService
        // will skip settlement when rates are empty, which matches "assume zero
        // funding" for the backtest. Good enough for WR analysis; off by a few
        // bps on long holds.
        return [];
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
        // Assume immediate fill at limit; backtest doesn't simulate maker
        // queue depth or post-only rejection.
        return [
            'orderId' => 'bt_lim_' . uniqid('', true),
            'price' => $price,
            'quantity' => $quantity,
            'status' => 'FILLED',
        ];
    }

    public function getOrderStatus(string $symbol, string $orderId): array
    {
        return [
            'orderId' => $orderId,
            'status' => 'FILLED',
            'executedQty' => 0.0,
            'avgPrice' => 0.0,
            'origQty' => 0.0,
        ];
    }

    public function cancelAlgoOrder(string $symbol, string $algoId): bool
    {
        return true;
    }

    public function getAlgoOrderStatus(string $symbol, string $algoId): array
    {
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
        return 'bt_listenkey';
    }

    public function keepAliveListenKey(): void {}
    public function closeListenKey(): void {}

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
        // During backtest we want the same per-symbol caps the live bot
        // would be subject to; otherwise the replay can size positions at
        // 25x on symbols Binance only allows 10x/20x on, and the live
        // trajectory would look nothing like the backtest's.
        if ($this->realExchange !== null) {
            try {
                return $this->realExchange->getMaxLeverage($symbol);
            } catch (\Throwable) {
                return 20;
            }
        }
        return 20;
    }

    public function setRealExchange(BinanceExchange $real): void
    {
        $this->realExchange = $real;
    }
}
