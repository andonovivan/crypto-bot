<?php

namespace App\Services\Strategy\LongMilddump;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongMilddumpStrategy extends LongStrategyBase
{
    public function __construct(LongMilddumpScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_milddump'; }
    public function label(): string { return 'Long: Mild-Dump Bounce (-5 to -10%)'; }
}
