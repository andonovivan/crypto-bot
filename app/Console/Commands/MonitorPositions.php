<?php

namespace App\Console\Commands;

use App\Models\Position;
use App\Services\TradingEngine;
use Illuminate\Console\Command;

class MonitorPositions extends Command
{
    protected $signature = 'bot:monitor';
    protected $description = 'Monitor open positions and close on SL/TP/expiry';

    public function handle(TradingEngine $engine): int
    {
        $openCount = Position::open()->count();

        if ($openCount === 0) {
            $this->info('No open positions to monitor.');
            return self::SUCCESS;
        }

        $this->info("Monitoring {$openCount} open position(s)...");

        $engine->monitorPositions();

        // Display current state
        $positions = Position::open()->get();

        if ($positions->isEmpty()) {
            $this->info('All positions have been closed.');
            return self::SUCCESS;
        }

        $this->table(
            ['Symbol', 'Entry', 'Current', 'P&L', 'SL', 'TP', 'Expires'],
            $positions->map(fn (Position $p) => [
                $p->symbol,
                number_format($p->entry_price, 4),
                number_format($p->current_price ?? 0, 4),
                $this->formatPnl($p->unrealized_pnl),
                number_format($p->stop_loss_price ?? 0, 4),
                number_format($p->take_profit_price ?? 0, 4),
                $p->expires_at?->diffForHumans(),
            ])->toArray()
        );

        return self::SUCCESS;
    }

    private function formatPnl(?float $pnl): string
    {
        if ($pnl === null) {
            return '-';
        }

        $prefix = $pnl >= 0 ? '+' : '';
        return $prefix . number_format($pnl, 4) . ' USDT';
    }
}
