<?php

namespace App\Services\Strategy\LongShallowpull;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongShallowpullStrategy extends LongStrategyBase
{
    public function __construct(LongShallowpullScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_shallowpull'; }
    public function label(): string { return 'Long: Shallow Pullback (+5 to +15%)'; }
}
