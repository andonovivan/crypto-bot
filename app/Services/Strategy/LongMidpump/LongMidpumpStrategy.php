<?php

namespace App\Services\Strategy\LongMidpump;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongMidpumpStrategy extends LongStrategyBase
{
    public function __construct(LongMidpumpScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_midpump'; }
    public function label(): string { return 'Long: Mid-Pump (+25 to +50%, overlaps short)'; }
}
