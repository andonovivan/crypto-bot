<?php

namespace App\Services\Strategy\LongHighpump;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongHighpumpStrategy extends LongStrategyBase
{
    public function __construct(LongHighpumpScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_highpump'; }
    public function label(): string { return 'Long: High-Pump Continuation (+50 to +100%, control)'; }
}
