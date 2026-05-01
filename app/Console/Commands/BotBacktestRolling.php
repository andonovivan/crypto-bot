<?php

namespace App\Console\Commands;

use App\Models\Position;
use App\Models\Trade;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Drives bot:backtest in a month-by-month rolling sequence so that long-range
 * 1m-resolution backtests fit in container memory. Each month runs as its own
 * `php artisan bot:backtest` subprocess (hard memory isolation between
 * chunks); --no-force-close is passed on every chunk except the last so open
 * positions naturally carry across month boundaries.
 *
 * State that survives across chunks:
 *   - Trade + Position rows (drives wallet balance, cooldown lookup, failed-
 *     entry cooldown).
 *   - Circuit-breaker cache keys.
 *
 * Things that DO NOT survive (set per chunk inside bot:backtest itself):
 *   - Settings::override() values — re-passed via --override on each chunk.
 *   - Carbon::setTestNow() — re-set inside each chunk's tick loop.
 *
 * Per-month stats are appended to a CSV log so you get a rolling equity curve
 * + trade rate breakdown without grepping output.
 */
class BotBacktestRolling extends Command
{
    protected $signature = 'bot:backtest-rolling
        {--from= : Start month YYYY-MM (inclusive, required)}
        {--to= : End month YYYY-MM (exclusive, required)}
        {--symbols= : Comma-separated symbol filter (passed to each chunk)}
        {--starting-balance=10000 : Starting wallet for the very first chunk; subsequent chunks accumulate via DB-resident trades}
        {--fixed-sizing : Pin each chunk to the starting balance so per-trade P&L reflects the strategy edge without compounding. Trade rows still accumulate; wallet_at_end column shows the would-be compounded value.}
        {--use-1m : Pass --use-1m to each chunk and (if --download-on-demand) fetch interval=1m archives for each month}
        {--download-on-demand : Run bot:download-history before each chunk to fetch missing kline_history rows for that month (and the prior month on chunk 1 for lead-in)}
        {--download-intervals=15m,1h : Intervals to fetch when --download-on-demand is on; "1m" is added automatically when --use-1m is set}
        {--cleanup-prior : Delete kline_history rows for months >=2 chunks behind the cursor (frees DB space; keeps the most-recent prior month for the next chunk lead-in)}
        {--summary-log= : Path to append per-month CSV summary (default: storage/app/backtest-rolling-{ts}.csv)}
        {--override=* : Repeatable Settings overrides (passed through to every chunk)}
        {--truncate : Wipe is_dry_run rows before chunk 1 (chunk 2+ continues from chunk 1 state)}';

    protected $description = 'Run bot:backtest month-by-month with state carrying across chunks (memory-friendly for 1m runs)';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        if (! preg_match('/^\d{4}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}$/', $to)) {
            $this->error('--from and --to must be YYYY-MM (got: from=' . $from . ', to=' . $to . ')');
            return self::FAILURE;
        }

        $startCarbon = Carbon::parse("{$from}-01", 'UTC')->startOfMonth();
        $endCarbon = Carbon::parse("{$to}-01", 'UTC')->startOfMonth();
        if ($endCarbon <= $startCarbon) {
            $this->error('--to must be after --from');
            return self::FAILURE;
        }

        $months = [];
        $cursor = $startCarbon->copy();
        while ($cursor < $endCarbon) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->copy()->addMonth();
        }
        $totalChunks = count($months);

        $symbols = (string) $this->option('symbols');
        $startingBalance = (float) $this->option('starting-balance');
        $fixedSizing = (bool) $this->option('fixed-sizing');
        $use1m = (bool) $this->option('use-1m');
        $download = (bool) $this->option('download-on-demand');
        $cleanup = (bool) $this->option('cleanup-prior');
        $truncate = (bool) $this->option('truncate');
        $overrides = (array) $this->option('override');

        $intervals = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('download-intervals')))));
        if ($use1m && ! in_array('1m', $intervals, true)) {
            $intervals[] = '1m';
        }

        $summaryLog = (string) ($this->option('summary-log')
            ?: storage_path(sprintf('app/backtest-rolling-%s.csv', date('Ymd-His'))));
        $this->info(sprintf('Rolling %d chunk(s): %s → %s', $totalChunks, $months[0], end($months)));
        $this->info('Summary log: ' . $summaryLog);
        if ($use1m) {
            $this->info('Mode: 1m ticker synthesis (15m strategy gates unchanged).');
        }

        $this->appendSummary($summaryLog, [
            'month', 'chunk', 'trades_closed', 'wins', 'losses', 'win_rate_pct',
            'pnl', 'fees', 'wallet_at_end', 'open_positions_at_end', 'duration_sec', 'subprocess_exit',
        ]);

        // The first chunk needs the prior month in DB for lead-in (200x15m + 50x1h
        // ≈ 50h, plus 1500x1m for the 1m ticker synthesis on its first tick). With
        // --download-on-demand we fetch it before chunk 1; otherwise we trust the
        // user to have pre-downloaded it.
        $priorMonthForLeadIn = $startCarbon->copy()->subMonth()->format('Y-m');

        foreach ($months as $i => $ym) {
            $isLast = ($i === $totalChunks - 1);
            $monthStart = "{$ym}-01";
            $monthEnd = Carbon::parse($monthStart, 'UTC')->copy()->addMonth()->format('Y-m-d');

            $this->newLine();
            $this->info(sprintf('===== Chunk %d/%d : %s → %s =====', $i + 1, $totalChunks, $monthStart, $monthEnd));

            // ---- Optional download step ----
            if ($download) {
                $monthsToFetch = $i === 0 ? [$priorMonthForLeadIn, $ym] : [$ym];
                foreach ($monthsToFetch as $fetchYm) {
                    $exit = $this->fetchMonthIfMissing($fetchYm, $intervals, $symbols);
                    if ($exit !== 0) {
                        $this->error("Download for {$fetchYm} exited with code {$exit}; aborting rolling run.");
                        return self::FAILURE;
                    }
                }
            }

            // Snapshot trade state so per-chunk metrics can be computed cleanly.
            $beforeId = (int) Trade::where('is_dry_run', true)->max('id');

            $args = [
                'php', '-d', 'memory_limit=-1', 'artisan', 'bot:backtest',
                '--from=' . $monthStart,
                '--to=' . $monthEnd,
                '--starting-balance=' . $startingBalance,
            ];
            if ($symbols !== '') {
                $args[] = '--symbols=' . $symbols;
            }
            if ($use1m) {
                $args[] = '--use-1m';
            }
            if ($fixedSizing) {
                $args[] = '--fixed-sizing';
            }
            if (! $isLast) {
                $args[] = '--no-force-close';
            }
            if ($truncate && $i === 0) {
                $args[] = '--truncate';
            }
            foreach ($overrides as $o) {
                $args[] = '--override=' . $o;
            }

            $start = microtime(true);
            $exit = $this->runProcess($args, sprintf('chunk-%d', $i + 1));
            $duration = (int) (microtime(true) - $start);

            if ($exit !== 0) {
                $this->error("Chunk {$ym} (bot:backtest) failed with exit={$exit}; aborting rolling run.");
                return self::FAILURE;
            }

            $chunkTrades = Trade::where('is_dry_run', true)->where('id', '>', $beforeId)->get();
            $wins = $chunkTrades->where('pnl', '>', 0)->count();
            $losses = $chunkTrades->where('pnl', '<=', 0)->count();
            // Mirror HistoricalReplayExchange::getAccountData() — wallet =
            // starting + realized P&L + closed funding + open-position funding.
            // Forgetting $openFunding would understate the wallet whenever a
            // position carries across the chunk boundary.
            $totalRealized = (float) Trade::where('is_dry_run', true)->sum('pnl');
            $closedFunding = (float) Trade::where('is_dry_run', true)->sum('funding_fee');
            $openFunding = (float) Position::open()->where('is_dry_run', true)->sum('funding_fee');
            $walletEnd = $startingBalance + $totalRealized + $closedFunding + $openFunding;
            $openAtEnd = Position::open()->where('is_dry_run', true)->count();

            $row = [
                'month' => $ym,
                'chunk' => $i + 1,
                'trades_closed' => $chunkTrades->count(),
                'wins' => $wins,
                'losses' => $losses,
                'win_rate_pct' => $chunkTrades->count() > 0
                    ? sprintf('%.2f', $wins / $chunkTrades->count() * 100)
                    : '0.00',
                'pnl' => sprintf('%.2f', (float) $chunkTrades->sum('pnl')),
                'fees' => sprintf('%.2f', (float) $chunkTrades->sum('fees')),
                'wallet_at_end' => sprintf('%.2f', $walletEnd),
                'open_positions_at_end' => $openAtEnd,
                'duration_sec' => $duration,
                'subprocess_exit' => $exit,
            ];
            $this->appendSummary($summaryLog, array_values($row));

            $this->newLine();
            $this->info(sprintf(
                'Chunk %s: %d trades closed (W=%d L=%d, WR=%s%%), pnl=$%s, wallet end=$%s, %d open carrying over, %ds',
                $ym,
                $chunkTrades->count(),
                $wins,
                $losses,
                $row['win_rate_pct'],
                $row['pnl'],
                $row['wallet_at_end'],
                $openAtEnd,
                $duration,
            ));

            // Cleanup processed kline data for chunks ≥2 behind. Keep month i-1
            // intact since chunk i+1 needs it for lead-in.
            if ($cleanup && $i >= 2) {
                $purgeYm = $months[$i - 2];
                $deleted = $this->purgeKlinesForMonth($purgeYm);
                $this->info("Cleanup: deleted {$deleted} kline_history rows for {$purgeYm}");
            }
        }

        $this->newLine();
        $this->info('Rolling backtest complete.');
        $this->info('Per-month summary: ' . $summaryLog);

        return self::SUCCESS;
    }

    /**
     * Fetch a single YYYY-MM month from data.binance.vision if not already
     * present. Computes how far back the month is from "now" so the existing
     * --months=N flag can target it; --skip-existing makes the call a no-op
     * if data is already there.
     */
    private function fetchMonthIfMissing(string $ym, array $intervals, string $symbols): int
    {
        $target = Carbon::parse("{$ym}-01", 'UTC')->startOfMonth();
        $cursor = Carbon::now('UTC')->startOfMonth()->subMonth();
        $monthsBack = 0;
        while ($cursor->greaterThanOrEqualTo($target)) {
            $monthsBack++;
            if ($cursor->equalTo($target)) {
                break;
            }
            $cursor = $cursor->subMonth();
        }
        if (! $cursor->equalTo($target)) {
            // Target month is in the future — Binance archives won't have it.
            $this->warn("Skipping download for {$ym} (target month is not yet published).");
            return 0;
        }

        $args = [
            'php', '-d', 'memory_limit=-1', 'artisan', 'bot:download-history',
            '--months=' . $monthsBack,
            '--intervals=' . implode(',', $intervals),
            '--skip-existing',
        ];
        if ($symbols !== '') {
            $args[] = '--symbols=' . $symbols;
        }
        return $this->runProcess($args, "download-{$ym}");
    }

    private function purgeKlinesForMonth(string $ym): int
    {
        $start = Carbon::parse("{$ym}-01", 'UTC')->getTimestampMs();
        $end = Carbon::parse("{$ym}-01", 'UTC')->copy()->addMonth()->getTimestampMs();
        return DB::table('kline_history')
            ->whereBetween('open_time', [$start, $end - 1])
            ->delete();
    }

    private function runProcess(array $cmd, string $label): int
    {
        $this->info(sprintf('[%s] %s', $label, implode(' ', $cmd)));
        $process = new Process($cmd, base_path());
        $process->setTimeout(10800); // 3 hours per subprocess; long enough for a 1m × 600-symbol month
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });
        return $process->getExitCode() ?? -1;
    }

    private function appendSummary(string $path, array $row): void
    {
        @mkdir(dirname($path), 0755, true);
        $fh = fopen($path, 'a');
        if (! $fh) {
            $this->warn("Could not open summary log {$path}; skipping write.");
            return;
        }
        fputcsv($fh, $row);
        fclose($fh);
    }
}
