<?php

namespace App\Models;

use App\Enums\SignalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PumpSignal extends Model
{
    protected $fillable = [
        'symbol',
        'pump_price',
        'peak_price',
        'current_price',
        'price_change_pct',
        'volume_multiplier',
        'drop_from_peak_pct',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'pump_price' => 'float',
            'peak_price' => 'float',
            'current_price' => 'float',
            'price_change_pct' => 'float',
            'volume_multiplier' => 'float',
            'drop_from_peak_pct' => 'float',
            'status' => SignalStatus::class,
        ];
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }
}
