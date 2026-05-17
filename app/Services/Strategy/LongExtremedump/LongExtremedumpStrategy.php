<?php

namespace App\Services\Strategy\LongExtremedump;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongExtremedumpStrategy extends LongStrategyBase
{
    public function __construct(LongExtremedumpScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_extremedump'; }
    public function label(): string { return 'Long: Extreme-Dump Bounce (-25 to -50%)'; }
}
