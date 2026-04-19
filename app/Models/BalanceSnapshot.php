<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BalanceSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'wallet_balance',
        'available_balance',
        'unrealized_profit',
        'margin_balance',
        'position_margin',
        'maint_margin',
        'open_positions',
        'is_dry_run',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'wallet_balance' => 'float',
            'available_balance' => 'float',
            'unrealized_profit' => 'float',
            'margin_balance' => 'float',
            'position_margin' => 'float',
            'maint_margin' => 'float',
            'open_positions' => 'integer',
            'is_dry_run' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
