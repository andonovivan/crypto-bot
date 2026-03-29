<?php

namespace App\Enums;

enum SignalStatus: string
{
    case Detected = 'detected';
    case ReversalConfirmed = 'reversal_confirmed';
    case Traded = 'traded';
    case Expired = 'expired';
    case Skipped = 'skipped';
}
