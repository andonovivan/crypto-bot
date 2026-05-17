<?php

namespace App\Services\Strategy\LongBase;

use App\Services\Exchange\ExchangeInterface;
use App\Services\Settings;
use App\Services\Strategy\Analysis;
use App\Services\Strategy\Candidate;
use App\Services\TechnicalAnalysis;
use Illuminate\Support\Facades\Log;

/**
 * Transient shared base for the 20 long-strategy variants under test.
 *
 * Provides the common boilerplate:
 *   • 24h ticker scan + pump/dump band + volume window → Candidate[]
 *   • 15m kline fetch with sim-time-aware 15s cache
 *   • EMA / ATR / body-pct / candle-color extraction
 *   • Funding-rate fetch
 *   • 1h HTF EMA gate (with fail-open on data errors)
 *
 * Variants extend this and override:
 *   • strategyKey() — the 'strategy.<key>.*' namespace this scanner reads
 *   • bandPass($changePct) — decides if a 24h price-change falls into the variant's band
 *   • evaluateSignal($symbol, $klines, $closes, $candle, $emaFastNow, $emaSlowNow, ...)
 *     — variant-specific signal logic (RSI, breakout, BTC regime, etc.); returns
 *       [bool $ok, ?string $blockedReason].
 *
 * The base is INTENTIONALLY transient: in Phase 5, the winning variant should
 * be flattened by inlining the helpers it actually uses, then this class
 * deleted alongside the 19 losing variants. Don't extend it from non-variant
 * code, and don't accrete features here that aren't needed by the bulk of
 * variants.
 */
abstract class LongScannerBase
{
    protected const KLINE_INTERVAL = '15m';
    protected const KLINE_LIMIT = 30;
    protected const CACHE_TTL_SECONDS = 15;

    /** @var array<string, array{data: array, at: int}> */
    protected array $klineCache = [];

    public function __construct(
        protected readonly ExchangeInterface $exchange,
        protected readonly TechnicalAnalysis $ta,
    ) {}

    /** Strategy key for `strategy.<key>.*` Settings lookups. */
    abstract public function strategyKey(): string;

    /** Variant-specific 24h band gate. Return true if the % qualifies. */
    abstract protected function bandPass(float $changePct): bool;

    /**
     * Variant-specific entry signal evaluation. Called after the 24h+volume
     * filter has selected this symbol AND the 15m klines have been fetched.
     *
     * @param  array<int, array>  $klines       Full 15m kline window (most recent last).
     * @param  array<int, float>  $closes       array_column($klines, 'close') for convenience.
     * @param  array              $closedCandle Last *closed* candle (klines[count-2]).
     * @param  array<int, float>  $emaFastValues Full EMA fast series (sized to $closes).
     * @param  array<int, float>  $emaSlowValues Full EMA slow series.
     * @param  array<int, float>  $atrValues    Full ATR14 series.
     * @param  float|null         $fundingRate  Latest funding rate, or null if unavailable.
     * @param  bool               $htfUpOk      Whether the 1h close > 1h EMA (fails open).
     *
     * @return array{0: bool, 1: ?string, 2?: array<string, mixed>}
     *   [ok, blockedReason, optional fields-for-Analysis]
     */
    abstract protected function evaluateSignal(
        string $symbol,
        array $klines,
        array $closes,
        array $closedCandle,
        array $emaFastValues,
        array $emaSlowValues,
        array $atrValues,
        ?float $fundingRate,
        bool $htfUpOk,
    ): array;

    protected function setting(string $key): mixed
    {
        return Settings::get('strategy.'.$this->strategyKey().'.'.$key);
    }

    /**
     * Default 24h-band + volume filter producing Candidate[]. Variants that
     * need an additional cross-symbol gate (BTC regime, etc.) can override.
     *
     * @return Candidate[]
     */
    public function getCandidates(): array
    {
        $minVolume = (float) $this->setting('min_volume_usdt');
        $maxVolume = (float) $this->setting('max_volume_usdt'); // 0 = no upper cap

        try {
            $tickers = $this->exchange->getFuturesTickers();
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch futures tickers', [
                'strategy' => $this->strategyKey(),
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if (! $this->preCandidateGate()) {
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
            if (! $this->bandPass($changePct)) {
                continue;
            }

            $candidates[] = new Candidate(
                symbol: $symbol,
                price: $price,
                priceChangePct: $changePct,
                volume: $volume,
                reason: $this->candidateReason(),
            );
        }

        usort($candidates, fn($a, $b) => $b->priceChangePct <=> $a->priceChangePct);

        return $candidates;
    }

    /**
     * Hook for variant-specific cross-symbol pre-gates (e.g. BTC regime
     * check) that short-circuit candidate emission entirely. Default: pass.
     */
    protected function preCandidateGate(): bool
    {
        return true;
    }

    /** Label attached to candidate rows in the dashboard scanner table. */
    protected function candidateReason(): string
    {
        return $this->strategyKey();
    }

    /**
     * Standard analyze pipeline used by most variants. Fetches klines,
     * computes EMA/ATR, runs HTF + funding gates, delegates the
     * variant-specific signal evaluation to evaluateSignal().
     */
    public function analyze(string $symbol): ?Analysis
    {
        $emaFastPeriod = (int) ($this->setting('ema_fast') ?: 9);
        $emaSlowPeriod = (int) ($this->setting('ema_slow') ?: 21);
        $htfEnabled = (bool) $this->setting('htf_filter_enabled');
        $htfEmaPeriod = max(1, (int) ($this->setting('htf_ema_period') ?: 21));

        $klines = $this->getCachedKlines($symbol);
        if ($klines === null || count($klines) < $emaSlowPeriod + 3) {
            return null;
        }

        $closes = array_column($klines, 'close');
        $last = count($closes) - 1;
        $prev = $last - 1;
        $closedCandle = $klines[$prev];

        $emaFastValues = $this->ta->calculateEMA($closes, $emaFastPeriod);
        $emaSlowValues = $this->ta->calculateEMA($closes, $emaSlowPeriod);
        $atrValues = $this->ta->calculateATR($klines, 14);

        $fundingRate = $this->getFundingRate($symbol);

        $htfUpOk = true;
        if ($htfEnabled) {
            $htfUpOk = $this->checkHigherTfUptrend($symbol, $htfEmaPeriod);
        }

        // Funding cap: long pays when positive — skip rates above the cap.
        $fundingMaxRate = (float) ($this->setting('funding_max_rate') ?: 0.001);
        if ($fundingRate !== null && $fundingRate > $fundingMaxRate) {
            return new Analysis(
                ok: false,
                blockedReason: sprintf('Funding rate too positive (%.5f > %.5f)', $fundingRate, $fundingMaxRate),
                atr: (float) ($atrValues[$last] ?? 0),
                fields: [
                    'currentPrice' => (float) $closes[$last],
                    'fundingRate' => $fundingRate,
                    'higherTfUptrendOk' => $htfUpOk,
                ],
            );
        }

        if ($htfEnabled && ! $htfUpOk) {
            return new Analysis(
                ok: false,
                blockedReason: '1h close below 1h EMA',
                atr: (float) ($atrValues[$last] ?? 0),
                fields: [
                    'currentPrice' => (float) $closes[$last],
                    'fundingRate' => $fundingRate,
                    'higherTfUptrendOk' => $htfUpOk,
                ],
            );
        }

        [$ok, $blocked, $extraFields] = $this->evaluateSignal(
            $symbol, $klines, $closes, $closedCandle,
            $emaFastValues, $emaSlowValues, $atrValues,
            $fundingRate, $htfUpOk,
        ) + [null, null, []];

        return new Analysis(
            ok: (bool) $ok,
            blockedReason: $blocked,
            atr: (float) ($atrValues[$last] ?? 0),
            fields: array_merge([
                'currentPrice' => (float) $closes[$last],
                'emaFast' => (float) $emaFastValues[$last],
                'emaSlow' => (float) $emaSlowValues[$last],
                'lastCandleGreen' => (float) $closedCandle['close'] > (float) $closedCandle['open'],
                'fundingRate' => $fundingRate,
                'higherTfUptrendOk' => $htfUpOk,
            ], is_array($extraFields) ? $extraFields : []),
        );
    }

    protected function getCachedKlines(string $symbol): ?array
    {
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
            Log::warning('Failed to fetch 15m klines', [
                'strategy' => $this->strategyKey(),
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function getFundingRate(string $symbol): ?float
    {
        try {
            $rates = $this->exchange->getFundingRates($symbol);
            if (isset($rates[$symbol]['fundingRate'])) {
                return (float) $rates[$symbol]['fundingRate'];
            }
        } catch (\Throwable $e) {
            Log::debug('Funding rate fetch failed', [
                'strategy' => $this->strategyKey(),
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
        }
        return null;
    }

    /** 1h close > 1h EMA, fails-open on data errors. Mirrors ShortScanner's logic. */
    protected function checkHigherTfUptrend(string $symbol, int $emaPeriod): bool
    {
        try {
            $limit = $emaPeriod + 5;
            $klines = $this->exchange->getKlines($symbol, '1h', $limit);
        } catch (\Throwable $e) {
            Log::debug('1h klines fetch failed — HTF filter fails open', [
                'strategy' => $this->strategyKey(),
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

    /**
     * Shared body-cap helper. Returns blocked-reason string or null.
     * Reads `max_candle_body_pct` from this variant's namespace.
     */
    protected function bodyCapBlocked(array $closedCandle): ?string
    {
        $maxBodyPct = (float) ($this->setting('max_candle_body_pct') ?: 5.0);
        $open = (float) $closedCandle['open'];
        $close = (float) $closedCandle['close'];
        if ($open <= 0) {
            return null;
        }
        $bodyPct = abs($close - $open) / $open * 100;
        if ($bodyPct > $maxBodyPct) {
            return sprintf('Candle body %.2f%% > %.2f%%', $bodyPct, $maxBodyPct);
        }
        return null;
    }

    protected function isGreen(array $candle): bool
    {
        return (float) $candle['close'] > (float) $candle['open'];
    }

    protected function isRed(array $candle): bool
    {
        return (float) $candle['close'] < (float) $candle['open'];
    }
}
