<?php

namespace App\Console\Commands;

use App\Enums\CloseReason;
use App\Enums\PositionStatus;
use App\Models\Position;
use App\Services\Exchange\ExchangeInterface;
use App\Services\TradingEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Long-running worker that subscribes to Binance Futures user-data stream
 * (listenKey). On ORDER_TRADE_UPDATE with X=FILLED events that match a
 * Position's sl_order_id or tp_order_id, it calls
 * TradingEngine::reconcileFillFromStream to cancel the sibling bracket and
 * record the close. This is the primary mechanism for live-mode position
 * closing since SL/TP are handled by Binance directly (see plan: Binance-
 * Managed Stops).
 *
 * listenKey lifecycle:
 *   - Created on connect via POST /fapi/v1/listenKey (60-min validity).
 *   - Kept alive every 30 min via PUT /fapi/v1/listenKey.
 *   - Any disconnect triggers a fresh listenKey on reconnect (the previous
 *     one may have expired during backoff).
 *
 * On dry-run: the underlying ExchangeDispatcher routes to DryRunExchange
 * which returns a fake listenKey; the ws connect to the real Binance url
 * with that fake key would fail — so we short-circuit and idle when dry_run
 * is active. The BotRun safety-reconcile loop handles dry-run positions
 * anyway.
 */
class BotWsUserData extends Command
{
    protected $signature = 'bot:ws-user-data';
    protected $description = 'Subscribe to Binance user-data stream and reconcile SL/TP fills';

    private const BACKOFF_MAX_SECONDS = 60;
    private const KEEPALIVE_INTERVAL_SECONDS = 1800;

    private bool $shouldStop = false;
    private int $backoffSeconds = 1;
    private TradingEngine $engine;
    private ExchangeInterface $exchange;
    private ?TimerInterface $keepAliveTimer = null;

    public function handle(TradingEngine $engine, ExchangeInterface $exchange): int
    {
        $this->engine = $engine;
        $this->exchange = $exchange;

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $this->info('WebSocket user-data worker starting');
        $this->newLine();

        $loop = Loop::get();
        $this->connect($loop);

        $loop->addPeriodicTimer(1.0, function () use ($loop) {
            if ($this->shouldStop) {
                $this->cancelKeepAlive($loop);
                try {
                    $this->exchange->closeListenKey();
                } catch (\Throwable $e) {
                    Log::warning('Failed to close listenKey on shutdown', ['error' => $e->getMessage()]);
                }
                $loop->stop();
            }
        });

        $loop->run();

        $this->info('WebSocket user-data worker stopped gracefully.');
        return self::SUCCESS;
    }

    private function connect(LoopInterface $loop): void
    {
        if ($this->shouldStop) {
            return;
        }

        try {
            $listenKey = $this->exchange->createListenKey();
        } catch (\Throwable $e) {
            Log::warning('listenKey create failed', ['error' => $e->getMessage()]);
            $this->error("listenKey create failed: {$e->getMessage()} — retrying in {$this->backoffSeconds}s");
            $this->scheduleReconnect($loop);
            return;
        }

        if ($listenKey === '') {
            $this->error("listenKey empty — retrying in {$this->backoffSeconds}s");
            $this->scheduleReconnect($loop);
            return;
        }

        $wsUrl = rtrim(config('crypto.binance.ws_url'), '/') . '/ws/' . $listenKey;

        $connector = new Connector($loop);

        $connector($wsUrl)->then(
            function (WebSocket $conn) use ($loop, $wsUrl) {
                $this->info('Connected to ' . preg_replace('#/ws/.*$#', '/ws/<key>', $wsUrl));
                $this->backoffSeconds = 1;

                $this->keepAliveTimer = $loop->addPeriodicTimer(
                    self::KEEPALIVE_INTERVAL_SECONDS,
                    function () use ($conn, $loop) {
                        try {
                            $this->exchange->keepAliveListenKey();
                        } catch (\Throwable $e) {
                            // A silent keep-alive failure means the listenKey is likely
                            // dead but the WS connection is still up. Binance will stop
                            // pushing events and we won't notice until the socket closes
                            // on its own. Force a disconnect so the reconnect path
                            // requests a fresh listenKey.
                            Log::warning('listenKey keep-alive failed — forcing reconnect', [
                                'error' => $e->getMessage(),
                            ]);
                            $this->warn("listenKey keep-alive failed — forcing reconnect: {$e->getMessage()}");
                            $this->cancelKeepAlive($loop);
                            try {
                                $conn->close();
                            } catch (\Throwable) {
                                // close handler fires and schedules reconnect with fresh listenKey
                            }
                        }
                    }
                );

                $conn->on('message', function (MessageInterface $msg) {
                    $this->handleMessage((string) $msg);
                });

                $conn->on('close', function ($code = null, $reason = null) use ($loop) {
                    Log::warning('user-data WS disconnected', ['code' => $code, 'reason' => $reason]);
                    $this->warn("Disconnected (code={$code}) — reconnecting in {$this->backoffSeconds}s");
                    $this->cancelKeepAlive($loop);
                    $this->scheduleReconnect($loop);
                });

                $conn->on('error', function (\Throwable $e) {
                    Log::warning('user-data WS error on open connection', ['error' => $e->getMessage()]);
                });
            },
            function (\Throwable $e) use ($loop) {
                Log::warning('user-data WS connect failed', ['error' => $e->getMessage()]);
                $this->error("Connect failed: {$e->getMessage()} — retrying in {$this->backoffSeconds}s");
                $this->scheduleReconnect($loop);
            }
        );
    }

    private function scheduleReconnect(LoopInterface $loop): void
    {
        if ($this->shouldStop) {
            return;
        }

        $delay = $this->backoffSeconds;
        $this->backoffSeconds = min($this->backoffSeconds * 2, self::BACKOFF_MAX_SECONDS);

        $loop->addTimer($delay, fn () => $this->connect($loop));
    }

    private function cancelKeepAlive(LoopInterface $loop): void
    {
        if ($this->keepAliveTimer !== null) {
            $loop->cancelTimer($this->keepAliveTimer);
            $this->keepAliveTimer = null;
        }
    }

    private function handleMessage(string $raw): void
    {
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return;
        }

        $eventType = $payload['e'] ?? null;
        if ($eventType !== 'ORDER_TRADE_UPDATE') {
            return;
        }

        $order = $payload['o'] ?? null;
        if (! is_array($order)) {
            return;
        }

        // Only terminal FILLED state reconciles; NEW/PARTIALLY_FILLED/CANCELED are ignored.
        if (($order['X'] ?? null) !== 'FILLED') {
            return;
        }

        $orderId = isset($order['i']) ? (string) $order['i'] : '';
        if ($orderId === '') {
            return;
        }

        try {
            $position = Position::where('status', PositionStatus::Open)
                ->where(function ($q) use ($orderId) {
                    $q->where('sl_order_id', $orderId)
                      ->orWhere('tp_order_id', $orderId);
                })
                ->first();
        } catch (\Throwable $e) {
            Log::warning('user-data: position lookup failed', [
                'error' => $e->getMessage(),
                'orderId' => $orderId,
            ]);
            return;
        }

        if ($position === null) {
            // Not one of our tracked brackets — entry fills, manual orders,
            // or already-reconciled positions. Log at debug for observability.
            Log::debug('user-data: FILLED event with no matching open position', [
                'orderId' => $orderId,
                'symbol' => $order['s'] ?? null,
            ]);
            return;
        }

        $reason = ((string) $position->sl_order_id === $orderId)
            ? CloseReason::StopLoss
            : CloseReason::TakeProfit;

        try {
            $this->engine->reconcileFillFromStream($position, $order, $reason);
        } catch (\Throwable $e) {
            Log::error('user-data: reconcileFillFromStream failed', [
                'symbol' => $position->symbol,
                'orderId' => $orderId,
                'reason' => $reason->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
