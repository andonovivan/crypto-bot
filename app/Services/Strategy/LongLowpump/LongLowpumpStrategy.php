<?php

namespace App\Services\Strategy\LongLowpump;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongLowpumpStrategy extends LongStrategyBase
{
    public function __construct(LongLowpumpScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_lowpump'; }
    public function label(): string { return 'Long: Low-Pump Continuation (+10 to +25%, thin-mid)'; }
}
