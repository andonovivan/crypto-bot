<?php

namespace App\Models;

use App\Enums\PositionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    protected $fillable = [
        'symbol',
        'side',
        'entry_price',
        'quantity',
        'position_size_usdt',
        'stop_loss_price',
        'take_profit_price',
        'current_price',
        'best_price',
        'unrealized_pnl',
        'leverage',
        'layer_count',
        'atr_value',
        'status',
        'exchange_order_id',
        'sl_order_id',
        'tp_order_id',
        'total_entry_fee',
        'funding_fee',
        'last_funding_at',
        'is_dry_run',
        'opened_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_price' => 'float',
            'quantity' => 'float',
            'position_size_usdt' => 'float',
            'stop_loss_price' => 'float',
            'take_profit_price' => 'float',
            'current_price' => 'float',
            'best_price' => 'float',
            'unrealized_pnl' => 'float',
            'leverage' => 'integer',
            'layer_count' => 'integer',
            'atr_value' => 'float',
            'total_entry_fee' => 'float',
            'funding_fee' => 'float',
            'last_funding_at' => 'datetime',
            'status' => PositionStatus::class,
            'is_dry_run' => 'boolean',
            'opened_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', PositionStatus::Open);
    }
}
