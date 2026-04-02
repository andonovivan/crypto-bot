<?php

namespace App\Console\Commands;

use App\Enums\PositionStatus;
use App\Models\Position;
use App\Models\Trade;
use App\Services\Settings;
use Illuminate\Console\Command;

class BotStatus extends Command
{
    protected $signature = 'bot:status';
    protected $description = 'Show bot status overview';

    public function handle(): int
    {
        $this->info('=== Crypto Bot Status ===');
        $this->newLine();

        // P&L summary
        $totalPnl = Trade::sum('pnl');
        $totalTrades = Trade::count();
        $winningTrades = Trade::where('pnl', '>', 0)->count();
        $winRate = $totalTrades > 0 ? round(($winningTrades / $totalTrades) * 100, 1) : 0;

        $openPositions = Position::open()->get();
        $unrealizedPnl = $openPositions->sum('unrealized_pnl');

        $this->info('P&L Summary:');
        $this->line("  Realized P&L:    " . $this->formatPnl($totalPnl));
        $this->line("  Unrealized P&L:  " . $this->formatPnl($unrealizedPnl));
        $this->line("  Combined P&L:    " . $this->formatPnl($totalPnl + $unrealizedPnl));
        $this->line("  Win Rate:        {$winRate}% ({$winningTrades}/{$totalTrades})");
        $this->newLine();

        // Open positions
        $this->info("Open Positions ({$openPositions->count()}):");
        if ($openPositions->isNotEmpty()) {
            $this->table(
                ['Symbol', 'Side', 'Entry', 'Current', 'P&L', 'P&L %', 'Opened'],
                $openPositions->map(fn (Position $p) => [
                    $p->symbol,
                    $p->side,
                    number_format($p->entry_price, 4),
                    number_format($p->current_price ?? 0, 4),
                    $this->formatPnl($p->unrealized_pnl),
                    $p->entry_price > 0 ? round(
                        $p->side === 'LONG'
                            ? ((($p->current_price ?? $p->entry_price) - $p->entry_price) / $p->entry_price) * 100
                            : (($p->entry_price - ($p->current_price ?? $p->entry_price)) / $p->entry_price) * 100,
                        2
                    ) . '%' : '-',
                    $p->opened_at->diffForHumans(),
                ])->toArray()
            );
        } else {
            $this->line('  No open positions.');
        }

        $this->newLine();
        $strategy = (string) Settings::get('strategy') ?: 'wave';
        $this->line("Strategy: {$strategy}");
        $this->line("Mode: " . (config('crypto.trading.dry_run') ? 'DRY RUN' : 'LIVE'));

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
