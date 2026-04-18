<?php

namespace App\Services;

class ShortCandidate
{
    public function __construct(
        public readonly string $symbol,
        public readonly float $price,
        public readonly float $priceChangePct,
        public readonly float $volume,
        public readonly string $reason,
    ) {}
}
