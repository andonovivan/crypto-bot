<?php

namespace App\Services;

class TechnicalAnalysis
{
    /**
     * Calculate Exponential Moving Average.
     *
     * @param array<float> $closes
     * @return array<float> EMA values (same length as input)
     */
    public function calculateEMA(array $closes, int $period): array
    {
        if (count($closes) < $period) {
            return array_fill(0, count($closes), 0.0);
        }

        $multiplier = 2.0 / ($period + 1);
        $ema = [];

        // Seed with SMA of first $period values
        $ema[0] = array_sum(array_slice($closes, 0, $period)) / $period;

        // Fill initial values as 0 (not enough data)
        $result = array_fill(0, $period - 1, 0.0);
        $result[] = $ema[0];

        for ($i = $period; $i < count($closes); $i++) {
            $ema[] = ($closes[$i] - end($ema)) * $multiplier + end($ema);
            $result[] = end($ema);
        }

        return $result;
    }

    /**
     * Calculate Relative Strength Index using Wilder's smoothing.
     *
     * @param array<float> $closes
     * @return array<float> RSI values (0-100)
     */
    public function calculateRSI(array $closes, int $period = 14): array
    {
        $count = count($closes);
        $result = array_fill(0, $count, 50.0); // default neutral

        if ($count < $period + 1) {
            return $result;
        }

        // Calculate price changes
        $changes = [];
        for ($i = 1; $i < $count; $i++) {
            $changes[] = $closes[$i] - $closes[$i - 1];
        }

        // Initial average gain/loss from first $period changes
        $avgGain = 0.0;
        $avgLoss = 0.0;
        for ($i = 0; $i < $period; $i++) {
            if ($changes[$i] > 0) {
                $avgGain += $changes[$i];
            } else {
                $avgLoss += abs($changes[$i]);
            }
        }
        $avgGain /= $period;
        $avgLoss /= $period;

        // First RSI value
        if ($avgLoss == 0) {
            $result[$period] = 100.0;
        } else {
            $rs = $avgGain / $avgLoss;
            $result[$period] = 100.0 - (100.0 / (1.0 + $rs));
        }

        // Subsequent values using Wilder's smoothing
        for ($i = $period; $i < count($changes); $i++) {
            $gain = $changes[$i] > 0 ? $changes[$i] : 0.0;
            $loss = $changes[$i] < 0 ? abs($changes[$i]) : 0.0;

            $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;

            if ($avgLoss == 0) {
                $result[$i + 1] = 100.0;
            } else {
                $rs = $avgGain / $avgLoss;
                $result[$i + 1] = 100.0 - (100.0 / (1.0 + $rs));
            }
        }

        return $result;
    }

    /**
     * Calculate MACD (12, 26, 9).
     *
     * @param array<float> $closes
     * @return array{macd: array<float>, signal: array<float>, histogram: array<float>}
     */
    public function calculateMACD(array $closes): array
    {
        $count = count($closes);
        $ema12 = $this->calculateEMA($closes, 12);
        $ema26 = $this->calculateEMA($closes, 26);

        // MACD line = EMA(12) - EMA(26)
        $macdLine = [];
        for ($i = 0; $i < $count; $i++) {
            $macdLine[] = $ema12[$i] - $ema26[$i];
        }

        // Signal line = EMA(9) of MACD line
        $signal = $this->calculateEMA($macdLine, 9);

        // Histogram = MACD - Signal
        $histogram = [];
        for ($i = 0; $i < $count; $i++) {
            $histogram[] = $macdLine[$i] - $signal[$i];
        }

        return [
            'macd' => $macdLine,
            'signal' => $signal,
            'histogram' => $histogram,
        ];
    }

    /**
     * Calculate Average True Range.
     *
     * @param array<array{high: float, low: float, close: float}> $klines
     * @return array<float> ATR values
     */
    public function calculateATR(array $klines, int $period = 14): array
    {
        $count = count($klines);
        $result = array_fill(0, $count, 0.0);

        if ($count < $period + 1) {
            return $result;
        }

        // Calculate True Range
        $tr = [0.0]; // first candle has no previous close
        for ($i = 1; $i < $count; $i++) {
            $highLow = $klines[$i]['high'] - $klines[$i]['low'];
            $highPrevClose = abs($klines[$i]['high'] - $klines[$i - 1]['close']);
            $lowPrevClose = abs($klines[$i]['low'] - $klines[$i - 1]['close']);
            $tr[] = max($highLow, $highPrevClose, $lowPrevClose);
        }

        // Initial ATR = SMA of first $period TRs (starting from index 1)
        $atr = array_sum(array_slice($tr, 1, $period)) / $period;
        $result[$period] = $atr;

        // Subsequent ATR using Wilder's smoothing
        for ($i = $period + 1; $i < $count; $i++) {
            $atr = (($atr * ($period - 1)) + $tr[$i]) / $period;
            $result[$i] = $atr;
        }

        return $result;
    }
}
