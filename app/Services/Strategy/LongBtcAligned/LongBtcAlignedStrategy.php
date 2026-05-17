<?php

namespace App\Services\Strategy\LongBtcAligned;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongBtcAlignedStrategy extends LongStrategyBase
{
    public function __construct(LongBtcAlignedScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_btc_aligned'; }
    public function label(): string { return 'Long: BTC-Aligned (BTC regime up + alt 0-25%)'; }
}
