<?php

namespace App\Services\Strategy\LongBreakoutNewHigh;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongBreakoutNewHighStrategy extends LongStrategyBase
{
    public function __construct(LongBreakoutNewHighScanner $scanner) { parent::__construct($scanner); }
    public function key(): string { return 'long_breakout_new_high'; }
    public function label(): string { return 'Long: Breakout New High (0 to +25%, vol spike)'; }
}
