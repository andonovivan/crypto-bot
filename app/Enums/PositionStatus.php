<?php

namespace App\Enums;

enum PositionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Expired = 'expired';
    case StoppedOut = 'stopped_out';
    case Failed = 'failed';
}
