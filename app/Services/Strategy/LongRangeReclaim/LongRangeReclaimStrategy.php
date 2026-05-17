<?php

namespace App\Services\Strategy\LongRangeReclaim;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongRangeReclaimStrategy extends LongStrategyBase
{
    public function __construct(LongRangeReclaimScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_range_reclaim'; }
    public function label(): string { return 'Long: Range Reclaim (-3 to +3% sideways)'; }
}
