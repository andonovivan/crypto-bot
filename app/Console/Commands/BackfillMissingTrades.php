<?php

namespace App\Console\Commands;

use App\Enums\CloseReason;
use App\Enums\PositionStatus;
use App\Models\Position;
use App\Models\Trade;
use App\Services\Exchange\BinanceExchange;
use Illuminate\Console\Command;

class BackfillMissingTrades extends Command
{
    protected $signature = 'bot:backfill-missing-trades {--dry-run : Print only, do not write to DB}';

    protected $description = 'Backfill Trade rows for positions #191, #195, #198 using /fapi/v1/userTrades data';

    public function handle(BinanceExchange $live): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $since = strtotime('2026-04-19 04:00:00') * 1000;

        $this->probe($live, $since);

        $this->line('');
        $this->info('--- Backfill plan ---');

        // Load positions.
        $giggle = Position::find(191);
        $river = Position::find(195);
        $sto = Position::find(198);

        if (! $giggle || ! $river || ! $sto) {
            $this->error('One of #191/#195/#198 not found.');
            return self::FAILURE;
        }

        // --- GIGGLE #191 ---
        $giggleFill = $this->closeFillFor($live, $giggle, $since);
        if (! $giggleFill) {
            $this->error('No GIGGLE close fill found');
            return self::FAILURE;
        }
        $this->applyUpdate($giggle, $giggleFill, $dryRun);

        // --- RIVER #195 ---
        $riverFill = $this->closeFillFor($live, $river, $since);
        if (! $riverFill) {
            $this->error('No RIVER close fill found');
            return self::FAILURE;
        }
        $this->applyUpdate($river, $riverFill, $dryRun);

        // --- STO #198 ---
        $stoFill = $this->closeFillFor($live, $sto, $since);
        if (! $stoFill) {
            $this->error('No STO close fill found');
            return self::FAILURE;
        }
        $this->applyInsertFromFailed($sto, $stoFill, $live, $dryRun);

        $this->info($dryRun ? 'DRY-RUN complete — no DB writes.' : 'Backfill complete.');
        return self::SUCCESS;
    }

    private function probe(BinanceExchange $live, int $since): void
    {
        $this->info('--- userTrades probe ---');
        foreach (['GIGGLEUSDT', 'RIVERUSDT', 'STOUSDT'] as $sym) {
            $trades = $live->getUserTrades($sym, $since, 500);
            $this->line("{$sym} — ".count($trades).' fills');
            $groups = [];
            foreach ($trades as $t) {
                $oid = $t['orderId'];
                $groups[$oid][] = $t;
            }
            foreach ($groups as $oid => $fills) {
                $qty = 0.0;
                $notional = 0.0;
                $pnl = 0.0;
                $comm = 0.0;
                $commAsset = $fills[0]['commissionAsset'];
                $side = $fills[0]['side'];
                $time = 0;
                foreach ($fills as $f) {
                    $qty += $f['qty'];
                    $notional += $f['qty'] * $f['price'];
                    $pnl += $f['realizedPnl'];
                    $comm += $f['commission'];
                    $time = max($time, $f['time']);
                }
                $avg = $qty > 0 ? $notional / $qty : 0;
                $this->line(sprintf(
                    '  orderId=%s side=%s qty=%.4f avg=%.8f pnl=%.4f comm=%.6f %s @%s',
                    $oid, $side, $qty, $avg, $pnl, $comm, $commAsset,
                    date('Y-m-d H:i:s', (int) ($time / 1000))
                ));
            }
        }
    }

    /**
     * Find the close-side fill group whose quantity best matches the position.
     * Returns array with price, qty, orderId, realizedPnl, commission, commissionAsset.
     */
    private function closeFillFor(BinanceExchange $live, Position $position, int $since): ?array
    {
        $closeSide = $position->side === 'LONG' ? 'SELL' : 'BUY';
        $trades = $live->getUserTrades($position->symbol, $since, 500);

        $groups = [];
        foreach ($trades as $t) {
            if ($t['side'] !== $closeSide) continue;
            $oid = (string) $t['orderId'];
            if (! isset($groups[$oid])) {
                $groups[$oid] = [
                    'qty' => 0.0, 'notional' => 0.0,
                    'pnl' => 0.0, 'commission' => 0.0,
                    'commissionAsset' => $t['commissionAsset'],
                    'time' => 0,
                ];
            }
            $groups[$oid]['qty'] += $t['qty'];
            $groups[$oid]['notional'] += $t['qty'] * $t['price'];
            $groups[$oid]['pnl'] += $t['realizedPnl'];
            $groups[$oid]['commission'] += $t['commission'];
            $groups[$oid]['time'] = max($groups[$oid]['time'], $t['time']);
        }

        $target = (float) $position->quantity;
        $best = null;
        $bestDelta = INF;
        foreach ($groups as $oid => $g) {
            $delta = abs($g['qty'] - $target);
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $best = ['orderId' => $oid] + $g;
            }
        }

        if (! $best || $best['qty'] <= 0) {
            return null;
        }

        return [
            'orderId' => $best['orderId'],
            'price' => $best['notional'] / $best['qty'],
            'qty' => $best['qty'],
            'realizedPnl' => $best['pnl'],
            'commission' => $best['commission'],
            'commissionAsset' => $best['commissionAsset'],
            'time' => $best['time'],
        ];
    }

    private function applyUpdate(Position $position, array $fill, bool $dryRun): void
    {
        $trade = Trade::where('position_id', $position->id)->where('type', 'close')->latest('id')->first();
        if (! $trade) {
            $this->error("Position #{$position->id}: no existing Trade row (expected one)");
            return;
        }

        $exitPrice = (float) $fill['price'];
        $qty = (float) $position->quantity;
        $entry = (float) $position->entry_price;
        $rawPnl = ($entry - $exitPrice) * $qty; // SHORT

        // Exit commission is quoted in USDT per Binance (unless BNB deduction).
        // Entry fee we keep from the original Position.total_entry_fee.
        $entryFee = (float) ($position->total_entry_fee ?? 0);
        $exitFee = (float) $fill['commission']; // USDT
        $totalFees = round($entryFee + $exitFee, 6);
        $pnl = round($rawPnl - $totalFees, 4);
        $pnlPct = $entry > 0 ? round(($rawPnl / ($entry * $qty)) * 100, 4) : 0;

        $this->line(sprintf(
            'Position #%d %s: exit %s -> %s, pnl %s -> %s, fees %s -> %s, oid "" -> %s',
            $position->id, $position->symbol,
            number_format($trade->exit_price, 8), number_format($exitPrice, 8),
            number_format($trade->pnl, 4), number_format($pnl, 4),
            number_format($trade->fees, 6), number_format($totalFees, 6),
            $fill['orderId']
        ));

        if ($dryRun) return;

        $trade->update([
            'exit_price' => $exitPrice,
            'pnl' => $pnl,
            'pnl_pct' => $pnlPct,
            'fees' => $totalFees,
            'exchange_order_id' => (string) $fill['orderId'],
        ]);

        $position->update([
            'current_price' => $exitPrice,
        ]);
    }

    private function applyInsertFromFailed(Position $position, array $fill, BinanceExchange $live, bool $dryRun): void
    {
        // STO #198 is status=Failed. The entry DID fill on Binance but we couldn't place
        // brackets and then closed out. Backfill: flip status to Closed, add a Trade row.
        $exitPrice = (float) $fill['price'];
        $qty = (float) $position->quantity;
        $entry = (float) $position->entry_price;
        $rawPnl = ($entry - $exitPrice) * $qty; // SHORT

        // Entry fee: compute from taker rate since we don't have it cached.
        $rates = $live->getCommissionRate($position->symbol);
        $entryFee = round($entry * $qty * $rates['taker'], 6);
        $exitFee = (float) $fill['commission'];
        $totalFees = round($entryFee + $exitFee, 6);
        $pnl = round($rawPnl - $totalFees, 4);
        $pnlPct = $entry > 0 ? round(($rawPnl / ($entry * $qty)) * 100, 4) : 0;

        $this->line(sprintf(
            'Position #%d %s: flip status=Failed->Closed, insert Trade (exit=%s, pnl=%s, fees=%s)',
            $position->id, $position->symbol,
            number_format($exitPrice, 8), number_format($pnl, 4), number_format($totalFees, 6)
        ));

        if ($dryRun) return;

        $existing = Trade::where('position_id', $position->id)->where('type', 'close')->first();
        if ($existing) {
            $this->warn("Trade row already exists for #{$position->id}, skipping insert");
            return;
        }

        Trade::create([
            'position_id' => $position->id,
            'symbol' => $position->symbol,
            'side' => $position->side,
            'type' => 'close',
            'entry_price' => $entry,
            'exit_price' => $exitPrice,
            'quantity' => $qty,
            'pnl' => $pnl,
            'pnl_pct' => $pnlPct,
            'fees' => $totalFees,
            'funding_fee' => 0,
            'close_reason' => CloseReason::Manual,
            'exchange_order_id' => (string) $fill['orderId'],
            'is_dry_run' => $position->is_dry_run,
        ]);

        $position->update([
            'status' => PositionStatus::Closed,
            'current_price' => $exitPrice,
            'total_entry_fee' => $entryFee,
        ]);
    }
}
