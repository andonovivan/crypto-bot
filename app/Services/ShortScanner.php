<?php

namespace App\Services;

use App\Services\Exchange\ExchangeInterface;
use Illuminate\Support\Facades\Log;

class ShortScanner
{
    private const KLINE_INTERVAL = '15m';
    private const KLINE_LIMIT = 30;
    private const CACHE_TTL_SECONDS = 15;
    private const FUNDING_MIN_RATE = -0.0005;

    private array $klineCache = [];

    public function __construct(
        private ExchangeInterface $exchange,
        private TechnicalAnalysis $ta,
    ) {}

    /**
     * @return ShortCandidate[]
     */
    public function getCandidates(): array
    {
        $pumpThreshold = (float) Settings::get('pump_threshold_pct') ?: 25.0;
        $dumpThreshold = (float) Settings::get('dump_threshold_pct') ?: 10.0;
        $minVolume = (float) Settings::get('min_volume_usdt') ?: 10_000_000;

        try {
            $tickers = $this->exchange->getFuturesTickers();
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch futures tickers', ['error' => $e->getMessage()]);
            return [];
        }

        $candidates = [];
        foreach ($tickers as $ticker) {
            $symbol = $ticker['symbol'] ?? null;
            $price = (float) ($ticker['price'] ?? 0);
            $changePct = (float) ($ticker['priceChangePct'] ?? 0);
            $volume = (float) ($ticker['volume'] ?? 0);

            if (! $symbol || $price <= 0 || $volume < $minVolume) {
                continue;
            }

            $reason = null;
            if ($changePct >= $pumpThreshold) {
                $reason = 'pump';
            } elseif ($changePct <= -$dumpThreshold) {
                $reason = 'dump';
            }

            if (! $reason) {
                continue;
            }

            $candidates[] = new ShortCandidate(
                symbol: $symbol,
                price: $price,
                priceChangePct: $changePct,
                volume: $volume,
                reason: $reason,
            );
        }

        usort($candidates, fn($a, $b) => abs($b->priceChangePct) <=> abs($a->priceChangePct));

        return $candidates;
    }

    public function analyze15m(string $symbol): ?ShortAnalysis
    {
        $emaFastPeriod = (int) Settings::get('ema_fast') ?: 9;
        $emaSlowPeriod = (int) Settings::get('ema_slow') ?: 21;
        $maxBodyPct = (float) Settings::get('max_candle_body_pct') ?: 3.0;
        $minRedCandles = max(1, (int) Settings::get('min_red_candles') ?: 2);
        $htfEnabled = (bool) Settings::get('htf_filter_enabled');
        $htfEmaPeriod = max(1, (int) Settings::get('htf_ema_period') ?: 21);

        $klines = $this->getCachedKlines($symbol);
        if ($klines === null || count($klines) < $emaSlowPeriod + 3) {
            return null;
        }

        $closes = array_column($klines, 'close');
        $last = count($closes) - 1;
        $prev = $last - 1;

        $emaFastValues = $this->ta->calculateEMA($closes, $emaFastPeriod);
        $emaSlowValues = $this->ta->calculateEMA($closes, $emaSlowPeriod);
        $atrValues = $this->ta->calculateATR($klines, 14);

        $emaFastNow = $emaFastValues[$last];
        $emaSlowNow = $emaSlowValues[$last];
        $emaFastPrev = $emaFastValues[$prev];
        $emaSlowPrev = $emaSlowValues[$prev];
        $currentPrice = $closes[$last];
        $atrNow = (float) ($atrValues[$last] ?? 0);

        // Use the last CLOSED candle (index prev) for the red/body check.
        $closedCandle = $klines[$prev];
        $open = (float) $closedCandle['open'];
        $close = (float) $closedCandle['close'];
        $lastCandleRed = $close < $open;
        $bodyPct = $open > 0 ? abs($close - $open) / $open * 100 : 0;

        // Prior closed candle (one before the most recent) — for the 2-red-candle gate.
        $priorCandle = $klines[$prev - 1] ?? null;
        $priorCandleRed = $priorCandle
            ? ((float) $priorCandle['close'] < (float) $priorCandle['open'])
            : false;

        $redCount = ($lastCandleRed ? 1 : 0) + ($priorCandleRed ? 1 : 0);

        $fundingRate = $this->getFundingRate($symbol);

        // Higher-timeframe confirmation (1h close below 1h EMA). Catches cases
        // where 15m looks weak but the larger trend is still up — those 15m
        // setups are bounce tops that get bought back into, which historically
        // wrecks the scalp strategy. Only fetched when enabled to avoid the
        // extra REST call per candidate on the cold path.
        $higherTfDowntrendOk = true;
        if ($htfEnabled) {
            $higherTfDowntrendOk = $this->checkHigherTfDowntrend($symbol, $htfEmaPeriod);
        }

        $blocked = null;
        if (! ($emaFastNow < $emaSlowNow)) {
            $blocked = 'EMA not down (current)';
        } elseif (! ($emaFastPrev < $emaSlowPrev)) {
            $blocked = 'EMA just crossed (prior candle up)';
        } elseif (! ($currentPrice < $emaFastNow)) {
            $blocked = 'Price above fast EMA';
        } elseif ($redCount < $minRedCandles) {
            $blocked = "Red candles {$redCount}/{$minRedCandles}";
        } elseif ($bodyPct > $maxBodyPct) {
            $blocked = "Candle body {$this->fmt($bodyPct)}% > {$maxBodyPct}%";
        } elseif ($fundingRate !== null && $fundingRate < self::FUNDING_MIN_RATE) {
            $blocked = 'Funding rate too negative';
        } elseif (! $higherTfDowntrendOk) {
            $blocked = '1h close above 1h EMA';
        }

        return new ShortAnalysis(
            currentPrice: $currentPrice,
            emaFast: $emaFastNow,
            emaSlow: $emaSlowNow,
            candleBodyPct: round($bodyPct, 3),
            lastCandleRed: $lastCandleRed,
            priorCandleRed: $priorCandleRed,
            fundingRate: $fundingRate,
            downtrendOk: $blocked === null,
            blockedReason: $blocked,
            atr: $atrNow,
            higherTfDowntrendOk: $higherTfDowntrendOk,
        );
    }

    private function checkHigherTfDowntrend(string $symbol, int $emaPeriod): bool
    {
        try {
            $limit = $emaPeriod + 5;
            $klines = $this->exchange->getKlines($symbol, '1h', $limit);
        } catch (\Throwable $e) {
            Log::debug('1h klines fetch failed — HTF filter fails open', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return true;
        }

        if (count($klines) < $emaPeriod + 1) {
            return true;
        }

        $closes = array_column($klines, 'close');
        $ema = $this->ta->calculateEMA($closes, $emaPeriod);
        $last = count($closes) - 1;

        return $closes[$last] < $ema[$last];
    }

    private function getFundingRate(string $symbol): ?float
    {
        try {
            $rates = $this->exchange->getFundingRates($symbol);
            if (isset($rates[$symbol]['fundingRate'])) {
                return (float) $rates[$symbol]['fundingRate'];
            }
        } catch (\Throwable $e) {
            Log::debug('Funding rate fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
        }
        return null;
    }

    private function getCachedKlines(string $symbol): ?array
    {
        $now = microtime(true);

        if (isset($this->klineCache[$symbol])) {
            $cached = $this->klineCache[$symbol];
            if ($now - $cached['at'] < self::CACHE_TTL_SECONDS) {
                return $cached['data'];
            }
        }

        try {
            $klines = $this->exchange->getKlines($symbol, self::KLINE_INTERVAL, self::KLINE_LIMIT);
            $this->klineCache[$symbol] = [
                'data' => $klines,
                'at' => $now,
            ];
            return $klines;
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch 15m klines', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fmt(float $v): string
    {
        return number_format($v, 2);
    }
}
