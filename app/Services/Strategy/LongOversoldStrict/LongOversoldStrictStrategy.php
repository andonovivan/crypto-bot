<?php

namespace App\Services\Strategy\LongOversoldStrict;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongOversoldStrictStrategy extends LongStrategyBase
{
    public function __construct(LongOversoldStrictScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_oversold_strict'; }
    public function label(): string { return 'Long: Strict Oversold (RSI<20 + volume)'; }
}
