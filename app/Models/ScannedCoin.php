<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScannedCoin extends Model
{
    protected $fillable = [
        'symbol',
        'price',
        'price_change_pct_24h',
        'volume_24h',
        'avg_volume_7d',
        'volume_multiplier',
        'high_24h',
        'low_24h',
        'last_scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'price_change_pct_24h' => 'float',
            'volume_24h' => 'float',
            'avg_volume_7d' => 'float',
            'volume_multiplier' => 'float',
            'high_24h' => 'float',
            'low_24h' => 'float',
            'last_scanned_at' => 'datetime',
        ];
    }
}
