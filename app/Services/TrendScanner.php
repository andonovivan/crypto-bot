<?php

namespace App\Services;

use App\Enums\SignalStatus;
use App\Models\TrendSignal;
use App\Services\Exchange\ExchangeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TrendScanner
{
    private const MAX_CANDIDATES = 50;
    private const KLINE_INTERVAL = '5m';
    private const KLINE_LIMIT = 100; // ~8 hours of 5m candles

    public function __construct(
        private ExchangeInterface $exchange,
        private TechnicalAnalysis $ta,
    ) {}

    /**
     * Scan futures pairs for trend signals.
     *
     * @return Collection<int, TrendSignal>
     */
    public function scan(): Collection
    {
        $minScore = (int) Settings::get('trend_min_score');
        $minVolumeUsdt = (float) Settings::get('min_volume_usdt');
        $signals = collect();

        Log::info('Starting trend scan', ['min_score' => $minScore]);

        $tickers = $this->exchange->getFuturesTickers();

        // Pre-filter: volume, tradability, and minimum intraday range
        $candidates = $this->preFilter($tickers, $minVolumeUsdt);

        Log::info('Trend scan candidates after pre-filter', ['count' => count($candidates)]);

        // Sort by absolute price change (most volatile first) and take top N
        usort($candidates, fn ($a, $b) => abs($b['priceChangePct']) <=> abs($a['priceChangePct']));
        $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES);

        foreach ($candidates as $ticker) {
            try {
                $result = $this->evaluateSymbol($ticker['symbol']);

                if ($result === null || $result['score'] < $minScore) {
                    continue;
                }

                // Check for existing active signal on this symbol
                $existing = TrendSignal::where('symbol', $ticker['symbol'])
                    ->whereIn('status', [SignalStatus::Detected, SignalStatus::ReversalConfirmed])
                    ->where('created_at', '>=', now()->subHours(4))
                    ->first();

                if ($existing) {
                    // Update existing signal if new score is higher
                    if ($result['score'] > $existing->score) {
                        $existing->update([
                            'score' => $result['score'],
                            'current_price' => $ticker['price'],
                            'ema_cross' => $result['ema_cross'],
                            'rsi_value' => $result['rsi'],
                            'macd_histogram' => $result['macd_histogram'],
                            'volume_ratio' => $result['volume_ratio'],
                        ]);
                    }
                    $signals->push($existing->fresh());
                    continue;
                }

                $signal = TrendSignal::create([
                    'symbol' => $ticker['symbol'],
                    'direction' => $result['direction'],
                    'score' => $result['score'],
                    'entry_price' => $ticker['price'],
                    'current_price' => $ticker['price'],
                    'ema_cross' => $result['ema_cross'],
                    'rsi_value' => $result['rsi'],
                    'macd_histogram' => $result['macd_histogram'],
                    'volume_ratio' => $result['volume_ratio'],
                    'status' => SignalStatus::Detected,
                    'expires_at' => now()->addHours((int) Settings::get('trend_max_hold_hours')),
                ]);

                Log::info('Trend signal detected', [
                    'symbol' => $ticker['symbol'],
                    'direction' => $result['direction'],
                    'score' => $result['score'],
                    'rsi' => round($result['rsi'], 2),
                    'macd_h' => round($result['macd_histogram'], 8),
                    'volume_ratio' => round($result['volume_ratio'], 2),
                ]);

                $signals->push($signal);
            } catch (\Throwable $e) {
                Log::warning('Failed to evaluate symbol for trend', [
                    'symbol' => $ticker['symbol'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Trend scan complete', ['signals_found' => $signals->count()]);

        return $signals;
    }

    /**
     * Evaluate a single symbol for trend signal.
     *
     * @return array{direction: string, score: int, ema_cross: bool, rsi: float, macd_histogram: float, volume_ratio: float}|null
     */
    public function evaluateSymbol(string $symbol): ?array
    {
        $klines = $this->exchange->getKlines($symbol, self::KLINE_INTERVAL, self::KLINE_LIMIT);

        if (count($klines) < 50) {
            return null; // Not enough data for reliable indicators
        }

        $closes = array_column($klines, 'close');
        $volumes = array_column($klines, 'volume');

        // Calculate indicators
        $ema9 = $this->ta->calculateEMA($closes, 9);
        $ema21 = $this->ta->calculateEMA($closes, 21);
        $ema50 = $this->ta->calculateEMA($closes, 50);
        $rsi = $this->ta->calculateRSI($closes);
        $macd = $this->ta->calculateMACD($closes);

        $last = count($closes) - 1;
        $prev = $last - 1;

        // Determine direction from EMA cross
        $ema9Now = $ema9[$last];
        $ema21Now = $ema21[$last];
        $ema9Prev = $ema9[$prev];
        $ema21Prev = $ema21[$prev];

        $emaCrossLong = $ema9Now > $ema21Now;
        $emaCrossShort = $ema9Now < $ema21Now;
        $freshCross = ($ema9Now > $ema21Now && $ema9Prev <= $ema21Prev)
            || ($ema9Now < $ema21Now && $ema9Prev >= $ema21Prev);

        if (!$emaCrossLong && !$emaCrossShort) {
            return null;
        }

        $direction = $emaCrossLong ? 'LONG' : 'SHORT';
        $rsiValue = $rsi[$last];
        $histogramNow = $macd['histogram'][$last];
        $histogramPrev = $macd['histogram'][$prev];
        $histogramPrev2 = $macd['histogram'][$prev - 1] ?? $histogramPrev;

        // Volume ratio: current candle vs average of last 20
        $recentVolumes = array_slice($volumes, -21, 20);
        $avgVolume = count($recentVolumes) > 0 ? array_sum($recentVolumes) / count($recentVolumes) : 1;
        $volumeRatio = $avgVolume > 0 ? $volumes[$last] / $avgVolume : 1.0;

        // Score the signal
        $score = 0;

        // 1. EMA Cross (30 pts) — fresh cross gets full points, existing alignment gets partial
        if ($freshCross) {
            $score += 30;
        } elseif ($emaCrossLong || $emaCrossShort) {
            $score += 15;
        }

        // 2. RSI confirmation (20 pts)
        if ($direction === 'LONG') {
            if ($rsiValue >= 40 && $rsiValue <= 70) {
                $score += 20;
            } elseif ($rsiValue > 70 && $rsiValue <= 80) {
                $score += 10; // Partial — getting overbought
            }
        } else {
            if ($rsiValue >= 30 && $rsiValue <= 60) {
                $score += 20;
            } elseif ($rsiValue >= 20 && $rsiValue < 30) {
                $score += 10; // Partial — getting oversold
            }
        }

        // Reject extreme RSI — likely to reverse
        if ($rsiValue > 85 || $rsiValue < 15) {
            return null;
        }

        // 3. MACD histogram confirmation (25 pts)
        if ($direction === 'LONG') {
            if ($histogramNow > 0 && $histogramNow > $histogramPrev) {
                $score += 25; // Positive and increasing
            } elseif ($histogramNow > 0) {
                $score += 12; // Positive but not increasing
            } elseif ($histogramNow > $histogramPrev && $histogramPrev > $histogramPrev2) {
                $score += 15; // Accelerating toward positive
            }
        } else {
            if ($histogramNow < 0 && $histogramNow < $histogramPrev) {
                $score += 25; // Negative and decreasing
            } elseif ($histogramNow < 0) {
                $score += 12; // Negative but not decreasing
            } elseif ($histogramNow < $histogramPrev && $histogramPrev < $histogramPrev2) {
                $score += 15; // Accelerating toward negative
            }
        }

        // 4. Volume confirmation (15 pts)
        if ($volumeRatio >= 2.0) {
            $score += 15;
        } elseif ($volumeRatio >= 1.5) {
            $score += 10;
        } elseif ($volumeRatio >= 1.2) {
            $score += 5;
        }

        // 5. Price vs EMA(50) trend alignment (10 pts)
        $priceNow = $closes[$last];
        $ema50Now = $ema50[$last];
        if ($ema50Now > 0) {
            if ($direction === 'LONG' && $priceNow > $ema50Now) {
                $score += 10;
            } elseif ($direction === 'SHORT' && $priceNow < $ema50Now) {
                $score += 10;
            }
        }

        return [
            'direction' => $direction,
            'score' => $score,
            'ema_cross' => $freshCross,
            'rsi' => $rsiValue,
            'macd_histogram' => $histogramNow,
            'volume_ratio' => $volumeRatio,
        ];
    }

    /**
     * Re-evaluate open positions for trend reversal (early exit signal).
     *
     * @return array<string, string> Symbol => 'exit' if trend reversed
     */
    public function checkForReversals(array $symbols): array
    {
        $reversals = [];

        foreach ($symbols as $symbol) {
            try {
                $result = $this->evaluateSymbol($symbol);

                if ($result === null) {
                    continue;
                }

                // If the signal direction flipped, flag for exit
                // (caller checks against position direction)
                $reversals[$symbol] = $result['direction'];
            } catch (\Throwable $e) {
                Log::warning('Failed to check trend reversal', [
                    'symbol' => $symbol,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $reversals;
    }

    /**
     * Expire old trend signals that were never traded.
     */
    public function expireStaleSignals(): int
    {
        return TrendSignal::where('status', SignalStatus::Detected)
            ->where(function ($query) {
                $query->where('created_at', '<', now()->subHours(4))
                    ->orWhere('expires_at', '<', now());
            })
            ->update(['status' => SignalStatus::Expired]);
    }

    /**
     * Pre-filter tickers for trend analysis candidates.
     */
    private function preFilter(array $tickers, float $minVolumeUsdt): array
    {
        $candidates = [];

        foreach ($tickers as $ticker) {
            // Skip low-liquidity coins
            if ($minVolumeUsdt > 0 && $ticker['volume'] < $minVolumeUsdt) {
                continue;
            }

            // Skip non-tradable symbols
            if (!$this->exchange->isTradable($ticker['symbol'])) {
                continue;
            }

            // Require minimum intraday range (skip dead coins)
            if ($ticker['high'] > 0) {
                $range = ($ticker['high'] - $ticker['low']) / $ticker['high'] * 100;
                if ($range < 2.0) {
                    continue;
                }
            }

            $candidates[] = $ticker;
        }

        return $candidates;
    }
}
