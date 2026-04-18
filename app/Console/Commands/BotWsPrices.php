<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;

/**
 * Long-running worker that subscribes to Binance Futures !markPrice@arr
 * stream and writes mark prices to the shared `binance:prices` cache key.
 * BinanceExchange::getPrice() already reads from that key, so this
 * transparently replaces REST polling with sub-second WebSocket updates.
 * On disconnect, reconnects with exponential backoff up to 60s.
 */
class BotWsPrices extends Command
{
    protected $signature = 'bot:ws-prices';
    protected $description = 'Subscribe to Binance mark-price WebSocket stream and populate the price cache';

    private const CACHE_KEY = 'binance:prices';
    private const CACHE_TTL_SECONDS = 30;
    private const BACKOFF_MAX_SECONDS = 60;

    private bool $shouldStop = false;
    private int $backoffSeconds = 1;

    public function handle(): int
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $wsUrl = config('crypto.binance.ws_url') . '/stream?streams=!markPrice@arr';

        $this->info('WebSocket price worker starting');
        $this->info("  URL: {$wsUrl}");
        $this->info('  Cache key: ' . self::CACHE_KEY);
        $this->newLine();

        $loop = Loop::get();
        $this->connect($loop, $wsUrl);

        $loop->addPeriodicTimer(1.0, function () use ($loop) {
            if ($this->shouldStop) {
                $loop->stop();
            }
        });

        $loop->run();

        $this->info('WebSocket worker stopped gracefully.');
        return self::SUCCESS;
    }

    private function connect(\React\EventLoop\LoopInterface $loop, string $wsUrl): void
    {
        if ($this->shouldStop) {
            return;
        }

        $connector = new Connector($loop);

        $connector($wsUrl)->then(
            function (WebSocket $conn) use ($loop, $wsUrl) {
                $this->info('Connected to ' . $wsUrl);
                $this->backoffSeconds = 1;

                $conn->on('message', function (MessageInterface $msg) {
                    $this->handleMessage((string) $msg);
                });

                $conn->on('close', function ($code = null, $reason = null) use ($loop, $wsUrl) {
                    Log::warning('WS disconnected', ['code' => $code, 'reason' => $reason]);
                    $this->warn("Disconnected (code={$code}) — reconnecting in {$this->backoffSeconds}s");
                    $this->scheduleReconnect($loop, $wsUrl);
                });

                $conn->on('error', function (\Throwable $e) {
                    Log::warning('WS error on open connection', ['error' => $e->getMessage()]);
                });
            },
            function (\Throwable $e) use ($loop, $wsUrl) {
                Log::warning('WS connect failed', ['error' => $e->getMessage()]);
                $this->error("Connect failed: {$e->getMessage()} — retrying in {$this->backoffSeconds}s");
                $this->scheduleReconnect($loop, $wsUrl);
            }
        );
    }

    private function scheduleReconnect(\React\EventLoop\LoopInterface $loop, string $wsUrl): void
    {
        if ($this->shouldStop) {
            return;
        }

        $delay = $this->backoffSeconds;
        $this->backoffSeconds = min($this->backoffSeconds * 2, self::BACKOFF_MAX_SECONDS);

        $loop->addTimer($delay, fn () => $this->connect($loop, $wsUrl));
    }

    private function handleMessage(string $raw): void
    {
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return;
        }

        // Combined-stream frames: { stream: "!markPrice@arr", data: [...] }
        $data = $payload['data'] ?? $payload;
        if (! is_array($data) || ! isset($data[0])) {
            return;
        }

        $existing = Cache::get(self::CACHE_KEY, []);
        $prices = is_array($existing) ? $existing : [];

        $updates = 0;
        foreach ($data as $tick) {
            $symbol = $tick['s'] ?? null;
            $markPrice = $tick['p'] ?? null;
            if (! $symbol || $markPrice === null) {
                continue;
            }
            if (! str_ends_with($symbol, 'USDT')) {
                continue;
            }
            $prices[$symbol] = (float) $markPrice;
            $updates++;
        }

        if ($updates > 0) {
            Cache::put(self::CACHE_KEY, $prices, self::CACHE_TTL_SECONDS);
        }
    }
}
