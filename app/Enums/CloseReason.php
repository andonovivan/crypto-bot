<?php

namespace App\Enums;

enum CloseReason: string
{
    case TakeProfit = 'take_profit';
    case StopLoss = 'stop_loss';
    case Expired = 'expired';
    case Manual = 'manual';
    case Reversed = 'reversed';
}
