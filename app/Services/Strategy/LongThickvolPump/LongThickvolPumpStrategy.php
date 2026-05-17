<?php

namespace App\Services\Strategy\LongThickvolPump;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongThickvolPumpStrategy extends LongStrategyBase
{
    public function __construct(LongThickvolPumpScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_thickvol_pump'; }
    public function label(): string { return 'Long: Thick-Volume Pump Scalp (+5 to +20%, 100M+)'; }
}
