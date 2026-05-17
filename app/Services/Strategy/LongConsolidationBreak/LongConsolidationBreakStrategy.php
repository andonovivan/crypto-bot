<?php

namespace App\Services\Strategy\LongConsolidationBreak;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongConsolidationBreakStrategy extends LongStrategyBase
{
    public function __construct(LongConsolidationBreakScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_consolidation_break'; }
    public function label(): string { return 'Long: Consolidation Break (+5 to +25%)'; }
}
