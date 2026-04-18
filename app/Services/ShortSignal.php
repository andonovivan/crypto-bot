<?php

namespace App\Services;

class ShortSignal
{
    public function __construct(
        public readonly string $symbol,
        public readonly float $priceChangePct,
        public readonly string $reason,
    ) {}
}
