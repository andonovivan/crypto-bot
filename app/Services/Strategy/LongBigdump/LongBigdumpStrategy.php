<?php

namespace App\Services\Strategy\LongBigdump;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongBigdumpStrategy extends LongStrategyBase
{
    public function __construct(LongBigdumpScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_bigdump'; }
    public function label(): string { return 'Long: Oversold Bigdump Bounce (-10 to -25%)'; }
}
