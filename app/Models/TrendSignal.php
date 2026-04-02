<?php

namespace App\Models;

use App\Enums\SignalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrendSignal extends Model
{
    protected $fillable = [
        'symbol',
        'direction',
        'score',
        'entry_price',
        'current_price',
        'ema_cross',
        'rsi_value',
        'macd_histogram',
        'volume_ratio',
        'status',
        'notes',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'entry_price' => 'float',
            'current_price' => 'float',
            'ema_cross' => 'boolean',
            'rsi_value' => 'float',
            'macd_histogram' => 'float',
            'volume_ratio' => 'float',
            'status' => SignalStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }
}
