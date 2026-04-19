<?php

namespace App\Console\Commands;

use App\Enums\PositionStatus;
use App\Models\BalanceSnapshot;
use App\Models\Position;
use App\Services\Exchange\ExchangeInterface;
use App\Services\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic wallet/available-balance snapshot. Runs every 5 minutes via the
 * Laravel scheduler. In live mode, values come from Binance's
 * /fapi/v2/account via BinanceExchange::getAccountData. In dry-run, the
 * DryRunExchange derives them from Trade/Position rows. Snapshots are
 * tagged with is_dry_run so the dashboard can show the right history
 * series for the active mode.
 */
class BotSnapshotBalance extends Command
{
    protected $signature = 'bot:snapshot-balance';
    protected $description = 'Capture a point-in-time balance snapshot for the equity chart';

    public function handle(ExchangeInterface $exchange): int
    {
        try {
            $account = $exchange->getAccountData();
        } catch (\Throwable $e) {
            Log::warning('Balance snapshot skipped: account fetch failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $isDryRun = (bool) Settings::get('dry_run');

        BalanceSnapshot::create([
            'wallet_balance' => $account['walletBalance'] ?? 0,
            'available_balance' => $account['availableBalance'] ?? 0,
            'unrealized_profit' => $account['unrealizedProfit'] ?? 0,
            'margin_balance' => $account['marginBalance'] ?? 0,
            'position_margin' => $account['positionMargin'] ?? 0,
            'maint_margin' => $account['maintMargin'] ?? 0,
            'open_positions' => Position::where('status', PositionStatus::Open)->count(),
            'is_dry_run' => $isDryRun,
            'created_at' => now(),
        ]);

        return self::SUCCESS;
    }
}
