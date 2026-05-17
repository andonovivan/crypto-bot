<?php

namespace App\Services\Strategy\LongBtcInverted;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongBtcInvertedStrategy extends LongStrategyBase
{
    public function __construct(LongBtcInvertedScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_btc_inverted'; }
    public function label(): string { return 'Long: BTC-Inverted (BTC down + alt 10-30% pump)'; }
}
