<?php

namespace App\Services;

use App\Enums\SignalStatus;
use App\Models\TrendSignal;
use App\Services\Exchange\ExchangeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TrendScanner
{
    private const KLINE_INTERVAL = '5m';
    private const KLINE_LIMIT = 100; // ~8 hours of 5m candles

    public function __construct(
        private ExchangeInterface $exchange,
        private TechnicalAnalysis $ta,
    ) {}

    /**
     * Scan watchlist symbols for trend signals.
     *
     * @return Collection<int, TrendSignal>
     */
    public function scan(): Collection
    {
        $minScore = (int) Settings::get('trend_min_score');
        $signals = collect();

        $watchlist = $this->getWatchlist();

        Log::info('Starting trend scan', ['min_score' => $minScore, 'watchlist' => $watchlist]);

        foreach ($watchlist as $symbol) {
            try {
                if (! $this->exchange->isTradable($symbol)) {
                    continue;
                }

                $result = $this->evaluateSymbol($symbol);

                if ($result === null || $result['score'] < $minScore) {
                    continue;
                }

                $price = $this->exchange->getPrice($symbol);

                // Check for existing active signal on this symbol
                $existing = TrendSignal::where('symbol', $symbol)
                    ->whereIn('status', [SignalStatus::Detected, SignalStatus::ReversalConfirmed])
                    ->where('created_at', '>=', now()->subHours(4))
                    ->first();

                if ($existing) {
                    // Update existing signal if new score is higher
                    if ($result['score'] > $existing->score) {
                        $existing->update([
                            'score' => $result['score'],
                            'current_price' => $price,
                            'ema_cross' => $result['ema_cross'],
                            'rsi_value' => $result['rsi'],
                            'macd_histogram' => $result['macd_histogram'],
                            'volume_ratio' => $result['volume_ratio'],
                            'atr_value' => $result['atr_value'],
                        ]);
                    }
                    $signals->push($existing->fresh());
                    continue;
                }

                $signal = TrendSignal::create([
                    'symbol' => $symbol,
                    'direction' => $result['direction'],
                    'score' => $result['score'],
                    'entry_price' => $price,
                    'current_price' => $price,
                    'ema_cross' => $result['ema_cross'],
                    'rsi_value' => $result['rsi'],
                    'macd_histogram' => $result['macd_histogram'],
                    'volume_ratio' => $result['volume_ratio'],
                    'atr_value' => $result['atr_value'],
                    'status' => SignalStatus::Detected,
                    'expires_at' => now()->addHours((int) Settings::get('trend_max_hold_hours')),
                ]);

                Log::info('Trend signal detected', [
                    'symbol' => $symbol,
                    'direction' => $result['direction'],
                    'score' => $result['score'],
                    'rsi' => round($result['rsi'], 2),
                    'macd_h' => round($result['macd_histogram'], 8),
                    'volume_ratio' => round($result['volume_ratio'], 2),
                    'atr' => round($result['atr_value'], 8),
                ]);

                $signals->push($signal);
            } catch (\Throwable $e) {
                Log::warning('Failed to evaluate symbol for trend', [
                    'symbol' => $symbol,
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
     * @return array{direction: string, score: int, ema_cross: bool, rsi: float, macd_histogram: float, volume_ratio: float, atr_value: float}|null
     */
    public function evaluateSymbol(string $symbol): ?array
    {
        $klines = $this->exchange->getKlines($symbol, self::KLINE_INTERVAL, self::KLINE_LIMIT);

        if (count($klines) < 50) {
            return null;
        }

        $closes = array_column($klines, 'close');
        $volumes = array_column($klines, 'volume');

        // Calculate indicators
        $ema9 = $this->ta->calculateEMA($closes, 9);
        $ema21 = $this->ta->calculateEMA($closes, 21);
        $ema50 = $this->ta->calculateEMA($closes, 50);
        $rsi = $this->ta->calculateRSI($closes);
        $macd = $this->ta->calculateMACD($closes);
        $atr = $this->ta->calculateATR($klines);

        $last = count($closes) - 1;
        $prev = $last - 1;

        // ATR value for SL/TP calculation
        $atrValue = $atr[$last] > 0 ? $atr[$last] : $atr[$prev];
        if ($atrValue <= 0) {
            return null; // Can't calculate volatility-based stops
        }

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

        // === HARD REQUIREMENTS (must pass all) ===

        // Reject extreme RSI — likely to reverse
        if ($rsiValue > 85 || $rsiValue < 15) {
            return null;
        }

        // MACD must confirm direction
        if ($direction === 'LONG' && $histogramNow <= 0) {
            return null;
        }
        if ($direction === 'SHORT' && $histogramNow >= 0) {
            return null;
        }

        // Volume must show participation
        if ($volumeRatio < 1.2) {
            return null;
        }

        // === SCORING ===
        $score = 0;

        // 1. EMA Cross (30 pts)
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
                $score += 10;
            }
        } else {
            if ($rsiValue >= 30 && $rsiValue <= 60) {
                $score += 20;
            } elseif ($rsiValue >= 20 && $rsiValue < 30) {
                $score += 10;
            }
        }

        // 3. MACD histogram strength (25 pts)
        if ($direction === 'LONG') {
            if ($histogramNow > $histogramPrev && $histogramPrev > $histogramPrev2) {
                $score += 25; // Multi-bar growing momentum
            } elseif ($histogramNow > $histogramPrev) {
                $score += 15; // Growing momentum
            } else {
                $score += 12; // Positive but weakening (still passed hard req)
            }
        } else {
            if ($histogramNow < $histogramPrev && $histogramPrev < $histogramPrev2) {
                $score += 25; // Multi-bar growing momentum
            } elseif ($histogramNow < $histogramPrev) {
                $score += 15; // Growing momentum
            } else {
                $score += 12; // Negative but weakening (still passed hard req)
            }
        }

        // 4. Volume strength (15 pts)
        if ($volumeRatio >= 2.0) {
            $score += 15;
        } elseif ($volumeRatio >= 1.5) {
            $score += 10;
        } else {
            $score += 5; // Already passed 1.2 hard requirement
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
            'atr_value' => $atrValue,
        ];
    }

    /**
     * Quick check if EMA alignment still holds for a symbol in a given direction.
     * Used by DCA logic to validate before adding layers.
     */
    public function isAlignmentValid(string $symbol, string $direction): bool
    {
        try {
            $klines = $this->exchange->getKlines($symbol, self::KLINE_INTERVAL, 30);
            if (count($klines) < 21) {
                return false;
            }

            $closes = array_column($klines, 'close');
            $ema9 = $this->ta->calculateEMA($closes, 9);
            $ema21 = $this->ta->calculateEMA($closes, 21);
            $last = count($closes) - 1;

            if ($direction === 'LONG') {
                return $ema9[$last] > $ema21[$last];
            }

            return $ema9[$last] < $ema21[$last];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Re-evaluate open positions for trend reversal (early exit signal).
     *
     * @return array<string, string> Symbol => new direction if flipped
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
     * Get the watchlist of symbols to scan.
     *
     * @return array<string>
     */
    private function getWatchlist(): array
    {
        $raw = (string) Settings::get('watchlist');

        if (empty($raw)) {
            return ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'XRPUSDT', 'DOGEUSDT'];
        }

        return array_filter(array_map('trim', explode(',', strtoupper($raw))));
    }
}
