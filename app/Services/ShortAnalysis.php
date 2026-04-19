<?php

namespace App\Services;

class ShortAnalysis
{
    public function __construct(
        public readonly float $currentPrice,
        public readonly float $emaFast,
        public readonly float $emaSlow,
        public readonly float $candleBodyPct,
        public readonly bool $lastCandleRed,
        public readonly bool $priorCandleRed,
        public readonly ?float $fundingRate,
        public readonly bool $downtrendOk,
        public readonly ?string $blockedReason,
        public readonly float $atr = 0.0,
        public readonly bool $higherTfDowntrendOk = true,
    ) {}
}
