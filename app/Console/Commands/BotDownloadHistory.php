<?php

namespace App\Console\Commands;

use App\Services\Exchange\ExchangeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Downloads historical kline data from data.binance.vision and populates the
 * `kline_history` table for use by bot:backtest. Pulls monthly zips for both
 * the 15m (entry/exit timeframe) and 1h (HTF filter) intervals.
 */
class BotDownloadHistory extends Command
{
    protected $signature = 'bot:download-history
        {--months=1 : Number of most recent completed calendar months to fetch}
        {--symbols= : Comma-separated list of symbols (default: all current USDT perps)}
        {--skip-existing : Skip (symbol,interval,month) combos that already have rows in kline_history}';

    protected $description = 'Download historical klines from data.binance.vision for backtesting';

    private const BASE_URL = 'https://data.binance.vision/data/futures/um/monthly/klines';
    private const INTERVALS = ['15m', '1h'];
    private const TMP_DIR = '/tmp/kline-dl';

    public function handle(ExchangeInterface $exchange): int
    {
        $months = max(1, (int) $this->option('months'));
        $symbolsOpt = $this->option('symbols');
        $skipExisting = (bool) $this->option('skip-existing');

        @mkdir(self::TMP_DIR, 0755, true);

        $symbols = $symbolsOpt
            ? array_filter(array_map('trim', explode(',', $symbolsOpt)))
            : $this->getUsdtPerps($exchange);

        $this->info(sprintf('Downloading %d month(s) of data for %d symbols × %d intervals',
            $months, count($symbols), count(self::INTERVALS)));

        $ym = $this->monthsToFetch($months);
        $totalJobs = count($symbols) * count(self::INTERVALS) * count($ym);

        $bar = $this->output->createProgressBar($totalJobs);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %symbol% %interval% %month% %status%');
        $bar->start();

        $stats = ['downloaded' => 0, 'skipped' => 0, 'missing' => 0, 'rows' => 0, 'errors' => 0];

        foreach ($symbols as $symbol) {
            foreach (self::INTERVALS as $interval) {
                foreach ($ym as $m) {
                    $bar->setMessage($symbol, 'symbol');
                    $bar->setMessage($interval, 'interval');
                    $bar->setMessage($m, 'month');

                    if ($skipExisting && $this->alreadyHave($symbol, $interval, $m)) {
                        $bar->setMessage('skip-existing', 'status');
                        $stats['skipped']++;
                        $bar->advance();
                        continue;
                    }

                    $result = $this->fetchMonth($symbol, $interval, $m);
                    $bar->setMessage($result['status'], 'status');
                    $stats[$result['status']] = ($stats[$result['status']] ?? 0) + 1;
                    if (isset($result['rows'])) {
                        $stats['rows'] += $result['rows'];
                    }
                    $bar->advance();
                }
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Done. downloaded=%d skipped=%d missing=%d errors=%d rows_inserted=%d',
            $stats['downloaded'] ?? 0,
            $stats['skipped'] ?? 0,
            $stats['missing'] ?? 0,
            $stats['errors'] ?? 0,
            $stats['rows'] ?? 0,
        ));

        return self::SUCCESS;
    }

    /**
     * @return string[] YYYY-MM strings, most recent completed month first.
     */
    private function monthsToFetch(int $count): array
    {
        $out = [];
        $cursor = now()->startOfMonth()->subMonth();
        for ($i = 0; $i < $count; $i++) {
            $out[] = $cursor->format('Y-m');
            $cursor = $cursor->subMonth();
        }
        return $out;
    }

    /**
     * @return string[]
     */
    private function getUsdtPerps(ExchangeInterface $exchange): array
    {
        try {
            $tickers = $exchange->getFuturesTickers();
        } catch (\Throwable $e) {
            $this->error('Failed to fetch tickers for symbol list: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($tickers as $t) {
            $sym = $t['symbol'] ?? '';
            if (str_ends_with($sym, 'USDT')) {
                $out[] = $sym;
            }
        }
        sort($out);
        return $out;
    }

    private function alreadyHave(string $symbol, string $interval, string $ym): bool
    {
        [$year, $month] = explode('-', $ym);
        $startMs = (new \DateTimeImmutable("{$ym}-01T00:00:00Z"))->getTimestamp() * 1000;
        $endMs = (new \DateTimeImmutable("{$ym}-01T00:00:00Z"))->modify('+1 month')->getTimestamp() * 1000;
        return DB::table('kline_history')
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->whereBetween('open_time', [$startMs, $endMs - 1])
            ->exists();
    }

    /**
     * @return array{status: string, rows?: int}
     */
    private function fetchMonth(string $symbol, string $interval, string $ym): array
    {
        $url = sprintf('%s/%s/%s/%s-%s-%s.zip', self::BASE_URL, $symbol, $interval, $symbol, $interval, $ym);
        $zipPath = self::TMP_DIR . "/{$symbol}-{$interval}-{$ym}.zip";

        try {
            $resp = Http::timeout(60)->get($url);
        } catch (\Throwable $e) {
            return ['status' => 'errors'];
        }
        if ($resp->status() === 404) {
            return ['status' => 'missing'];
        }
        if (! $resp->successful()) {
            return ['status' => 'errors'];
        }

        file_put_contents($zipPath, $resp->body());

        $extractDir = self::TMP_DIR . "/extract-{$symbol}-{$interval}-{$ym}";
        @mkdir($extractDir, 0755, true);

        $r = 0;
        exec('unzip -o -q ' . escapeshellarg($zipPath) . ' -d ' . escapeshellarg($extractDir), $_, $r);
        if ($r !== 0) {
            @unlink($zipPath);
            $this->cleanupDir($extractDir);
            return ['status' => 'errors'];
        }

        $csvFiles = glob($extractDir . '/*.csv');
        if (! $csvFiles) {
            @unlink($zipPath);
            $this->cleanupDir($extractDir);
            return ['status' => 'errors'];
        }

        $rows = $this->ingestCsv($csvFiles[0], $symbol, $interval);

        @unlink($zipPath);
        $this->cleanupDir($extractDir);

        return ['status' => 'downloaded', 'rows' => $rows];
    }

    private function ingestCsv(string $path, string $symbol, string $interval): int
    {
        $fp = fopen($path, 'r');
        if (! $fp) {
            return 0;
        }

        $batch = [];
        $batchSize = 1000;
        $total = 0;

        while (($line = fgets($fp)) !== false) {
            $parts = str_getcsv($line);
            if (count($parts) < 9) {
                continue;
            }
            if (! is_numeric($parts[0])) {
                continue;
            }
            $batch[] = [
                'symbol' => $symbol,
                'interval' => $interval,
                'open_time' => (int) $parts[0],
                'open' => (string) $parts[1],
                'high' => (string) $parts[2],
                'low' => (string) $parts[3],
                'close' => (string) $parts[4],
                'volume' => (string) $parts[5],
                'close_time' => (int) $parts[6],
                'quote_volume' => (string) $parts[7],
                'trade_count' => (int) $parts[8],
            ];
            if (count($batch) >= $batchSize) {
                DB::table('kline_history')->upsert(
                    $batch,
                    ['symbol', 'interval', 'open_time'],
                    ['open', 'high', 'low', 'close', 'volume', 'close_time', 'quote_volume', 'trade_count']
                );
                $total += count($batch);
                $batch = [];
            }
        }
        fclose($fp);

        if (! empty($batch)) {
            DB::table('kline_history')->upsert(
                $batch,
                ['symbol', 'interval', 'open_time'],
                ['open', 'high', 'low', 'close', 'volume', 'close_time', 'quote_volume', 'trade_count']
            );
            $total += count($batch);
        }

        return $total;
    }

    private function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
