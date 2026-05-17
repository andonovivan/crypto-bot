<?php

namespace App\Services\Strategy\LongExtremepump;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongExtremepumpStrategy extends LongStrategyBase
{
    public function __construct(LongExtremepumpScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_extremepump'; }
    public function label(): string { return 'Long: Extreme Pump (+100%+)'; }
}
