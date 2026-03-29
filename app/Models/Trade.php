<?php

namespace App\Models;

use App\Enums\CloseReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends Model
{
    protected $fillable = [
        'position_id',
        'symbol',
        'side',
        'type',
        'entry_price',
        'exit_price',
        'quantity',
        'pnl',
        'pnl_pct',
        'fees',
        'close_reason',
        'exchange_order_id',
        'is_dry_run',
    ];

    protected function casts(): array
    {
        return [
            'entry_price' => 'float',
            'exit_price' => 'float',
            'quantity' => 'float',
            'pnl' => 'float',
            'pnl_pct' => 'float',
            'fees' => 'float',
            'close_reason' => CloseReason::class,
            'is_dry_run' => 'boolean',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
