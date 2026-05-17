<?php

namespace App\Services\Strategy\LongDeeppull;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongDeeppullStrategy extends LongStrategyBase
{
    public function __construct(LongDeeppullScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_deeppull'; }
    public function label(): string { return 'Long: Deep Pullback (+5 to +25%, 2-3 reds)'; }
}
