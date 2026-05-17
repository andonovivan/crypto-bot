<?php

namespace App\Services\Strategy\LongThinvolPump;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongThinvolPumpStrategy extends LongStrategyBase
{
    public function __construct(LongThinvolPumpScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_thinvol_pump'; }
    public function label(): string { return 'Long: Thin-Volume Pump (+10 to +30%, 1M-5M)'; }
}
