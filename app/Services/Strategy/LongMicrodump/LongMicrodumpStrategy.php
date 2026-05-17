<?php

namespace App\Services\Strategy\LongMicrodump;

use App\Services\Strategy\LongBase\LongStrategyBase;

class LongMicrodumpStrategy extends LongStrategyBase
{
    public function __construct(LongMicrodumpScanner $scanner)
    {
        parent::__construct($scanner);
    }

    public function key(): string
    {
        return 'long_microdump';
    }

    public function label(): string
    {
        return 'Long: Micro-Dump Bounce (-2 to -5%)';
    }
}
