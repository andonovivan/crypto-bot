<?php

namespace App\Services\Strategy\LongContinuation;

use App\Services\Exchange\ExchangeInterface;
use App\Services\Settings;
use App\Services\Strategy\Analysis;
use App\Services\Strategy\Candidate;
use App\Services\TechnicalAnalysis;
use Illuminate\Support\Facades\Log;

/**
 * Mirror of ShortScanner for the long-continuation strategy. Targets the
 * +50–100% 24h pump band that ShortScanner explicitly skips because of the
 * continuation pattern (median 24h close +12.6% further). Entry confirms a
 * 15m uptrend (EMA fast > slow, last N green candles, body cap) plus a 1h
 * HTF up-trend filter.
 *
 * Settings are read from `strategy.long_continuation.*`. Funding guard is
 * inverted from the short scanner: longs PAY funding when the rate is
 * positive, so we skip rates ABOVE a configurable cap (default +0.10%).
 */
class LongContinuationScanner
{
    private const KLINE_INTERVAL = '15m';
    private const KLINE_LIMIT = 30;
    private const CACHE_TTL_SECONDS = 15;

    /** @var array<string, array{at: int, data: array}> */
    private array $klineCache = [];

    public function __construct(
        private ExchangeInterface $exchange,
        private TechnicalAnalysis $ta,
    ) {}

    /** @return Candidate[] */
    public function getCandidates(): array
    {
        $pumpMin = (float) Settings::get('strategy.long_continuation.pump_threshold_pct') ?: 50.0;
        $pumpMax = (float) Settings::get('strategy.long_continuation.pump_max_pct'); // 0 = no cap
        $minVolume = (float) Settings::get('strategy.long_continuation.min_volume_usdt') ?: 5_000_000;
        $maxVolume = (float) Settings::get('strategy.long_continuation.max_volume_usdt'); // 0 = no cap

        try {
            $tickers = $this->exchange->getFuturesTickers();
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch futures tickers (long scanner)', ['error' => $e->getMessage()]);
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
            if ($maxVolume > 0 && $volume > $maxVolume) {
                continue;
            }

            // Strict-greater on the lower bound so the +50% boundary belongs
            // to the SHORT strategy (which uses pump_max_pct=50 inclusive).
            // Bands therefore never overlap.
            if ($changePct <= $pumpMin) {
                continue;
            }
            if ($pumpMax > 0 && $changePct > $pumpMax) {
                continue;
            }

            $candidates[] = new Candidate(
                symbol: $symbol,
                price: $price,
                priceChangePct: $changePct,
                volume: $volume,
                reason: 'pump_continuation',
            );
        }

        // Sort by 24h gain magnitude descending — the strongest continuations
        // get first crack at remaining position slots.
        usort($candidates, fn ($a, $b) => $b->priceChangePct <=> $a->priceChangePct);

        return $candidates;
    }

    public function analyze(string $symbol): ?Analysis
    {
        $emaFastPeriod = (int) Settings::get('strategy.long_continuation.ema_fast') ?: 9;
        $emaSlowPeriod = (int) Settings::get('strategy.long_continuation.ema_slow') ?: 21;
        $maxBodyPct = (float) Settings::get('strategy.long_continuation.max_candle_body_pct') ?: 5.0;
        $minGreenCandles = max(1, (int) Settings::get('strategy.long_continuation.min_green_candles') ?: 2);
        $strict = (bool) Settings::get('strategy.long_continuation.strict_uptrend_enabled');
        $htfEnabled = (bool) Settings::get('strategy.long_continuation.htf_filter_enabled');
        $htfEmaPeriod = max(1, (int) Settings::get('strategy.long_continuation.htf_ema_period') ?: 21);
        $fundingMaxRate = (float) Settings::get('strategy.long_continuation.funding_max_rate');
        if ($fundingMaxRate <= 0) {
            $fundingMaxRate = 0.001;
        }

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

        // Last CLOSED candle (index prev) for the green/body check.
        $closedCandle = $klines[$prev];
        $open = (float) $closedCandle['open'];
        $close = (float) $closedCandle['close'];
        $lastCandleGreen = $close > $open;
        $bodyPct = $open > 0 ? abs($close - $open) / $open * 100 : 0;

        $priorCandle = $klines[$prev - 1] ?? null;
        $priorCandleGreen = $priorCandle
            ? ((float) $priorCandle['close'] > (float) $priorCandle['open'])
            : false;

        $greenCount = ($lastCandleGreen ? 1 : 0) + ($priorCandleGreen ? 1 : 0);

        $fundingRate = $this->getFundingRate($symbol);

        $higherTfUptrendOk = true;
        if ($htfEnabled) {
            $higherTfUptrendOk = $this->checkHigherTfUptrend($symbol, $htfEmaPeriod);
        }

        $blocked = null;
        if ($strict) {
            if (! ($emaFastNow > $emaSlowNow)) {
                $blocked = 'EMA not up (current)';
            } elseif (! ($emaFastPrev > $emaSlowPrev)) {
                $blocked = 'EMA just crossed (prior candle down)';
            } elseif (! ($currentPrice > $emaFastNow)) {
                $blocked = 'Price below fast EMA';
            } elseif ($greenCount < $minGreenCandles) {
                $blocked = "Green candles {$greenCount}/{$minGreenCandles}";
            } elseif ($bodyPct > $maxBodyPct) {
                $blocked = sprintf('Candle body %.2f%% > %.2f%%', $bodyPct, $maxBodyPct);
            } elseif ($fundingRate !== null && $fundingRate > $fundingMaxRate) {
                $blocked = sprintf('Funding rate too positive (%.4f > %.4f)', $fundingRate, $fundingMaxRate);
            } elseif (! $higherTfUptrendOk) {
                $blocked = '1h close below 1h EMA';
            }
        } else {
            if ($fundingRate !== null && $fundingRate > $fundingMaxRate) {
                $blocked = 'Funding rate too positive';
            }
        }

        return new Analysis(
            ok: $blocked === null,
            blockedReason: $blocked,
            atr: $atrNow,
            fields: [
                'currentPrice' => $currentPrice,
                'emaFast' => $emaFastNow,
                'emaSlow' => $emaSlowNow,
                'candleBodyPct' => round($bodyPct, 3),
                'lastCandleGreen' => $lastCandleGreen,
                'priorCandleGreen' => $priorCandleGreen,
                'fundingRate' => $fundingRate,
                'higherTfUptrendOk' => $higherTfUptrendOk,
            ],
        );
    }

    private function checkHigherTfUptrend(string $symbol, int $emaPeriod): bool
    {
        try {
            $limit = $emaPeriod + 5;
            $klines = $this->exchange->getKlines($symbol, '1h', $limit);
        } catch (\Throwable $e) {
            Log::debug('1h klines fetch failed (long HTF) — fail open', [
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

        return $closes[$last] > $ema[$last];
    }

    private function getFundingRate(string $symbol): ?float
    {
        try {
            $rates = $this->exchange->getFundingRates($symbol);
            if (isset($rates[$symbol]['fundingRate'])) {
                return (float) $rates[$symbol]['fundingRate'];
            }
        } catch (\Throwable $e) {
            Log::debug('Funding rate fetch failed (long)', ['symbol' => $symbol, 'error' => $e->getMessage()]);
        }
        return null;
    }

    private function getCachedKlines(string $symbol): ?array
    {
        // Sim-time clock for backtest reproducibility — see ShortScanner for
        // the full rationale on why microtime() would break determinism.
        $now = \Illuminate\Support\Carbon::now()->getTimestamp();

        if (isset($this->klineCache[$symbol])) {
            $cached = $this->klineCache[$symbol];
            if ($now - $cached['at'] < self::CACHE_TTL_SECONDS) {
                return $cached['data'];
            }
        }

        try {
            $klines = $this->exchange->getKlines($symbol, self::KLINE_INTERVAL, self::KLINE_LIMIT);
            $this->klineCache[$symbol] = ['data' => $klines, 'at' => $now];
            return $klines;
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch 15m klines (long)', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
