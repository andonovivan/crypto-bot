<?php

namespace App\Services\Exchange;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BinanceExchange implements ExchangeInterface
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;

    private const PRICE_CACHE_TTL = 10; // seconds
    private const EXCHANGE_INFO_CACHE_TTL = 3600; // 1 hour
    private const FUNDING_RATE_CACHE_TTL = 60; // 1 minute (rates change every 8h)
    private const BOOK_TICKER_CACHE_TTL = 2; // 2s — fresh enough for entry, dampens burst load
    private const RATE_LIMIT_WARN_THRESHOLD = 1800; // warn at 75% of 2400

    public function __construct()
    {
        $this->baseUrl = config('crypto.binance.base_url');
        $this->apiKey = config('crypto.binance.api_key');
        $this->apiSecret = config('crypto.binance.api_secret');
    }

    public function getFuturesTickers(): array
    {
        $response = $this->publicRequest('GET', '/fapi/v1/ticker/24hr');
        $tickers = [];

        foreach ($response as $ticker) {
            if (! str_ends_with($ticker['symbol'], 'USDT')) {
                continue;
            }

            $tickers[] = [
                'symbol' => $ticker['symbol'],
                'price' => (float) $ticker['lastPrice'],
                'priceChangePct' => (float) $ticker['priceChangePercent'],
                'volume' => (float) $ticker['quoteVolume'],
                'high' => (float) $ticker['highPrice'],
                'low' => (float) $ticker['lowPrice'],
            ];
        }

        // Cache all prices from this bulk response
        $priceMap = [];
        foreach ($tickers as $t) {
            $priceMap[$t['symbol']] = $t['price'];
        }
        Cache::put('binance:prices', $priceMap, self::PRICE_CACHE_TTL);

        return $tickers;
    }

    public function getPrice(string $symbol): float
    {
        // Try cache first
        $cached = Cache::get('binance:prices');
        if ($cached && isset($cached[$symbol])) {
            return $cached[$symbol];
        }

        // Single symbol fallback
        $response = $this->publicRequest('GET', '/fapi/v1/ticker/price', [
            'symbol' => $symbol,
        ]);

        return (float) $response['price'];
    }

    /**
     * Get prices for multiple symbols in a single API call.
     *
     * @param array<string> $symbols
     * @return array<string, float> Symbol => price map
     */
    public function getPrices(array $symbols): array
    {
        if (empty($symbols)) {
            return [];
        }

        // Try cache first
        $cached = Cache::get('binance:prices');
        if ($cached) {
            $result = [];
            $missing = [];
            foreach ($symbols as $symbol) {
                if (isset($cached[$symbol])) {
                    $result[$symbol] = $cached[$symbol];
                } else {
                    $missing[] = $symbol;
                }
            }
            if (empty($missing)) {
                return $result;
            }
        }

        // Fetch all prices in one call (no symbol param = all symbols)
        $response = $this->publicRequest('GET', '/fapi/v1/ticker/price');

        $priceMap = [];
        foreach ($response as $item) {
            $priceMap[$item['symbol']] = (float) $item['price'];
        }

        Cache::put('binance:prices', $priceMap, self::PRICE_CACHE_TTL);

        $result = [];
        foreach ($symbols as $symbol) {
            if (isset($priceMap[$symbol])) {
                $result[$symbol] = $priceMap[$symbol];
            }
        }

        return $result;
    }

    /**
     * Get exchange info with tradability status and LOT_SIZE filters.
     * Cached for 1 hour.
     *
     * @return array<string, array{status: string, stepSize: float, minQty: float, minNotional: float}>
     */
    public function getExchangeInfo(): array
    {
        $cached = Cache::get('binance:exchange_info');
        if ($cached) {
            return $cached;
        }

        $response = $this->publicRequest('GET', '/fapi/v1/exchangeInfo');

        $info = [];
        foreach ($response['symbols'] ?? [] as $symbol) {
            $stepSize = 1.0;
            $minQty = 0.0;
            $minNotional = 0.0;

            foreach ($symbol['filters'] ?? [] as $filter) {
                if ($filter['filterType'] === 'LOT_SIZE') {
                    $stepSize = (float) $filter['stepSize'];
                    $minQty = (float) $filter['minQty'];
                }
                if ($filter['filterType'] === 'MIN_NOTIONAL') {
                    $minNotional = (float) ($filter['notional'] ?? $filter['minNotional'] ?? 0);
                }
            }

            $info[$symbol['symbol']] = [
                'status' => $symbol['status'],
                'stepSize' => $stepSize,
                'minQty' => $minQty,
                'minNotional' => $minNotional,
            ];
        }

        Cache::put('binance:exchange_info', $info, self::EXCHANGE_INFO_CACHE_TTL);

        return $info;
    }

    /**
     * Check if a symbol is currently tradable on the exchange.
     */
    public function isTradable(string $symbol): bool
    {
        $info = $this->getExchangeInfo();

        return isset($info[$symbol]) && $info[$symbol]['status'] === 'TRADING';
    }

    public function getKlines(string $symbol, string $interval = '1h', int $limit = 24): array
    {
        $response = $this->publicRequest('GET', '/fapi/v1/klines', [
            'symbol' => $symbol,
            'interval' => $interval,
            'limit' => $limit,
        ]);

        return array_map(fn ($k) => [
            'openTime' => $k[0],
            'open' => (float) $k[1],
            'high' => (float) $k[2],
            'low' => (float) $k[3],
            'close' => (float) $k[4],
            'volume' => (float) $k[5],
        ], $response);
    }

    public function setLeverage(string $symbol, int $leverage): bool
    {
        $response = $this->signedRequest('POST', '/fapi/v1/leverage', [
            'symbol' => $symbol,
            'leverage' => $leverage,
        ]);

        return isset($response['leverage']);
    }

    public function setMarginType(string $symbol, string $marginType): bool
    {
        try {
            $this->signedRequest('POST', '/fapi/v1/marginType', [
                'symbol' => $symbol,
                'marginType' => $marginType,
            ]);

            return true;
        } catch (\Throwable $e) {
            // -4046: "No need to change margin type" — already set to the requested mode.
            if (str_contains($e->getMessage(), '-4046')
                || str_contains($e->getMessage(), 'No need to change margin type')
            ) {
                return true;
            }

            throw $e;
        }
    }

    public function openShort(string $symbol, float $quantity): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/order', [
            'symbol' => $symbol,
            'side' => 'SELL',
            'type' => 'MARKET',
            'quantity' => $this->formatQuantity($quantity, $symbol),
        ]);

        return [
            'orderId' => (string) $response['orderId'],
            'price' => (float) ($response['avgPrice'] ?? $response['price'] ?? 0),
            'quantity' => (float) $response['executedQty'],
        ];
    }

    public function closeShort(string $symbol, float $quantity): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/order', [
            'symbol' => $symbol,
            'side' => 'BUY',
            'type' => 'MARKET',
            'quantity' => $this->formatQuantity($quantity, $symbol),
            'reduceOnly' => 'true',
        ]);

        return [
            'orderId' => (string) $response['orderId'],
            'price' => (float) ($response['avgPrice'] ?? $response['price'] ?? 0),
            'quantity' => (float) $response['executedQty'],
        ];
    }

    public function openLong(string $symbol, float $quantity): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/order', [
            'symbol' => $symbol,
            'side' => 'BUY',
            'type' => 'MARKET',
            'quantity' => $this->formatQuantity($quantity, $symbol),
        ]);

        return [
            'orderId' => (string) $response['orderId'],
            'price' => (float) ($response['avgPrice'] ?? $response['price'] ?? 0),
            'quantity' => (float) $response['executedQty'],
        ];
    }

    public function closeLong(string $symbol, float $quantity): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/order', [
            'symbol' => $symbol,
            'side' => 'SELL',
            'type' => 'MARKET',
            'quantity' => $this->formatQuantity($quantity, $symbol),
            'reduceOnly' => 'true',
        ]);

        return [
            'orderId' => (string) $response['orderId'],
            'price' => (float) ($response['avgPrice'] ?? $response['price'] ?? 0),
            'quantity' => (float) $response['executedQty'],
        ];
    }

    public function setStopLoss(string $symbol, float $stopPrice, float $quantity, string $side = 'SHORT'): array
    {
        // For SHORT: SL triggers a BUY to close. For LONG: SL triggers a SELL to close.
        $closeSide = $side === 'LONG' ? 'SELL' : 'BUY';

        $response = $this->signedRequest('POST', '/fapi/v1/order', [
            'symbol' => $symbol,
            'side' => $closeSide,
            'type' => 'STOP_MARKET',
            'stopPrice' => $this->formatPrice($stopPrice),
            'quantity' => $this->formatQuantity($quantity, $symbol),
            'reduceOnly' => 'true',
        ]);

        return ['orderId' => (string) $response['orderId']];
    }

    public function setTakeProfit(string $symbol, float $takeProfitPrice, float $quantity, string $side = 'SHORT'): array
    {
        // For SHORT: TP triggers a BUY to close. For LONG: TP triggers a SELL to close.
        $closeSide = $side === 'LONG' ? 'SELL' : 'BUY';

        $response = $this->signedRequest('POST', '/fapi/v1/order', [
            'symbol' => $symbol,
            'side' => $closeSide,
            'type' => 'TAKE_PROFIT_MARKET',
            'stopPrice' => $this->formatPrice($takeProfitPrice),
            'quantity' => $this->formatQuantity($quantity, $symbol),
            'reduceOnly' => 'true',
        ]);

        return ['orderId' => (string) $response['orderId']];
    }

    public function getBalance(): float
    {
        return $this->getAccountData()['availableBalance'];
    }

    public function getAccountData(): array
    {
        $cached = Cache::get('binance:account_data');
        if ($cached) {
            return $cached;
        }

        $response = $this->signedRequest('GET', '/fapi/v2/account');

        $data = [
            'walletBalance' => (float) ($response['totalWalletBalance'] ?? 0),
            'availableBalance' => (float) ($response['availableBalance'] ?? 0),
            'unrealizedProfit' => (float) ($response['totalUnrealizedProfit'] ?? 0),
            'marginBalance' => (float) ($response['totalMarginBalance'] ?? 0),
            'positionMargin' => (float) ($response['totalPositionInitialMargin'] ?? 0),
            'maintMargin' => (float) ($response['totalMaintMargin'] ?? 0),
        ];

        Cache::put('binance:account_data', $data, self::PRICE_CACHE_TTL);

        return $data;
    }

    public function getCommissionRate(string $symbol): array
    {
        $cacheKey = "binance:commission:{$symbol}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $response = $this->signedRequest('GET', '/fapi/v1/commissionRate', [
            'symbol' => $symbol,
        ]);

        $rates = [
            'maker' => (float) ($response['makerCommissionRate'] ?? 0.0002),
            'taker' => (float) ($response['takerCommissionRate'] ?? 0.0005),
        ];

        Cache::put($cacheKey, $rates, self::EXCHANGE_INFO_CACHE_TTL);

        return $rates;
    }

    public function getOpenPositions(): array
    {
        $response = $this->signedRequest('GET', '/fapi/v2/positionRisk');
        $positions = [];

        foreach ($response as $pos) {
            $qty = (float) $pos['positionAmt'];
            if ($qty == 0) {
                continue;
            }

            $positions[] = [
                'symbol' => $pos['symbol'],
                'quantity' => abs($qty),
                'entryPrice' => (float) $pos['entryPrice'],
                'unrealizedPnl' => (float) $pos['unRealizedProfit'],
            ];
        }

        return $positions;
    }

    public function cancelOrders(string $symbol): bool
    {
        $this->signedRequest('DELETE', '/fapi/v1/allOpenOrders', [
            'symbol' => $symbol,
        ]);

        return true;
    }

    public function cancelOrder(string $symbol, string $orderId): bool
    {
        try {
            $this->signedRequest('DELETE', '/fapi/v1/order', [
                'symbol' => $symbol,
                'orderId' => $orderId,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Order may already be filled or cancelled — treat as success
            // -2011: "Unknown order sent" (already filled/cancelled)
            // -2013: "Order does not exist"
            if (str_contains($e->getMessage(), 'Unknown order')
                || str_contains($e->getMessage(), 'UNKNOWN_ORDER')
                || str_contains($e->getMessage(), '-2011')
                || str_contains($e->getMessage(), '-2013')
                || str_contains($e->getMessage(), 'Order does not exist')
            ) {
                Log::info('Order already gone when cancelling', [
                    'symbol' => $symbol,
                    'orderId' => $orderId,
                ]);

                return true;
            }

            throw $e;
        }
    }

    public function calculateQuantity(string $symbol, float $usdtAmount, float $price): float
    {
        if ($price <= 0) {
            return 0;
        }

        $quantity = $usdtAmount / $price;

        return $this->roundStepSize($quantity, $symbol);
    }

    /**
     * Get the current API rate limit usage.
     *
     * @return array{used: int, limit: int}
     */
    public function getRateLimitUsage(): array
    {
        return [
            'used' => (int) Cache::get('binance:rate_weight', 0),
            'limit' => 2400,
        ];
    }

    /**
     * Make a public (unsigned) API request.
     */
    private function publicRequest(string $method, string $endpoint, array $params = []): array
    {
        $this->checkRateLimit();

        $url = $this->baseUrl . $endpoint;

        $response = Http::timeout(10)
            ->withHeaders(['X-MBX-APIKEY' => $this->apiKey])
            ->$method($url, $params);

        $this->trackRateLimit($response);

        if ($response->failed()) {
            Log::error('Binance API error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException("Binance API error: {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Make a signed API request with HMAC authentication.
     */
    private function signedRequest(string $method, string $endpoint, array $params = []): array
    {
        $this->checkRateLimit();

        $params['timestamp'] = (int) (microtime(true) * 1000);
        $params['recvWindow'] = 5000;

        $queryString = http_build_query($params);
        $signature = hash_hmac('sha256', $queryString, $this->apiSecret);
        $params['signature'] = $signature;

        $url = $this->baseUrl . $endpoint;

        $response = Http::timeout(10)
            ->withHeaders(['X-MBX-APIKEY' => $this->apiKey]);

        $response = match (strtoupper($method)) {
            'GET' => $response->get($url, $params),
            'POST' => $response->asForm()->post($url, $params),
            'DELETE' => $response->delete($url, $params),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };

        $this->trackRateLimit($response);

        if ($response->failed()) {
            Log::error('Binance signed API error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException("Binance API error: {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Track rate limit weight from Binance response headers.
     */
    private function trackRateLimit($response): void
    {
        $weight = $response->header('X-MBX-USED-WEIGHT-1M');

        if ($weight !== null) {
            $weight = (int) $weight;
            Cache::put('binance:rate_weight', $weight, 60);

            if ($weight >= self::RATE_LIMIT_WARN_THRESHOLD) {
                Log::warning('Binance rate limit approaching', [
                    'used_weight' => $weight,
                    'limit' => 2400,
                ]);
            }
        }
    }

    /**
     * Check if we're approaching the rate limit before making a request.
     */
    private function checkRateLimit(): void
    {
        $used = (int) Cache::get('binance:rate_weight', 0);

        if ($used >= 2300) {
            Log::warning('Binance rate limit critical, pausing', ['used_weight' => $used]);
            sleep(10);
        }
    }

    private function formatQuantity(float $quantity, string $symbol = ''): string
    {
        $decimals = $this->getQuantityDecimals($symbol);

        return rtrim(rtrim(number_format($quantity, $decimals, '.', ''), '0'), '.');
    }

    private function formatPrice(float $price): string
    {
        return rtrim(rtrim(number_format($price, 8, '.', ''), '0'), '.');
    }

    /**
     * Round quantity to the nearest valid step size from exchangeInfo.
     */
    private function roundStepSize(float $quantity, string $symbol): float
    {
        $info = $this->getExchangeInfo();

        if (isset($info[$symbol])) {
            $stepSize = $info[$symbol]['stepSize'];
            if ($stepSize > 0) {
                $quantity = floor($quantity / $stepSize) * $stepSize;
            }

            $minQty = $info[$symbol]['minQty'];
            if ($quantity < $minQty) {
                return 0;
            }
        }

        return $quantity;
    }

    /**
     * Determine decimal places for quantity formatting based on stepSize.
     */
    private function getQuantityDecimals(string $symbol): int
    {
        $info = $this->getExchangeInfo();

        if (isset($info[$symbol])) {
            $stepSize = $info[$symbol]['stepSize'];
            if ($stepSize > 0 && $stepSize < 1) {
                return max(0, (int) -floor(log10($stepSize)));
            }
        }

        return 8; // default
    }

    public function getFundingRates(?string $symbol = null): array
    {
        $cacheKey = 'binance:funding_rates';

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            if ($symbol !== null) {
                return isset($cached[$symbol]) ? [$symbol => $cached[$symbol]] : [];
            }

            return $cached;
        }

        // Fetch all symbols (weight 10) and cache — more efficient than per-symbol calls
        $response = $this->publicRequest('GET', '/fapi/v1/premiumIndex');

        $rates = [];
        foreach ($response as $item) {
            $rates[$item['symbol']] = [
                'fundingRate' => (float) $item['lastFundingRate'],
                'nextFundingTime' => (int) $item['nextFundingTime'],
                'markPrice' => (float) $item['markPrice'],
            ];
        }

        Cache::put($cacheKey, $rates, self::FUNDING_RATE_CACHE_TTL);

        if ($symbol !== null) {
            return isset($rates[$symbol]) ? [$symbol => $rates[$symbol]] : [];
        }

        return $rates;
    }

    public function getOrderBookTop(string $symbol): array
    {
        $cacheKey = "binance:book_ticker:{$symbol}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->publicRequest('GET', '/fapi/v1/ticker/bookTicker', [
            'symbol' => $symbol,
        ]);

        $top = [
            'bid' => (float) ($response['bidPrice'] ?? 0),
            'ask' => (float) ($response['askPrice'] ?? 0),
        ];

        Cache::put($cacheKey, $top, self::BOOK_TICKER_CACHE_TTL);

        return $top;
    }

    public function openShortLimit(string $symbol, float $quantity, float $price, bool $postOnly = true): array
    {
        $params = [
            'symbol' => $symbol,
            'side' => 'SELL',
            'type' => 'LIMIT',
            'quantity' => $this->formatQuantity($quantity, $symbol),
            'price' => $this->formatPrice($price),
            'timeInForce' => $postOnly ? 'GTX' : 'GTC',
        ];

        $response = $this->signedRequest('POST', '/fapi/v1/order', $params);

        return [
            'orderId' => (string) $response['orderId'],
            'price' => (float) ($response['price'] ?? $price),
            'quantity' => (float) ($response['origQty'] ?? $quantity),
            'status' => (string) ($response['status'] ?? 'NEW'),
        ];
    }

    public function getOrderStatus(string $symbol, string $orderId): array
    {
        $response = $this->signedRequest('GET', '/fapi/v1/order', [
            'symbol' => $symbol,
            'orderId' => $orderId,
        ]);

        return [
            'orderId' => (string) $response['orderId'],
            'status' => (string) ($response['status'] ?? 'UNKNOWN'),
            'executedQty' => (float) ($response['executedQty'] ?? 0),
            'avgPrice' => (float) ($response['avgPrice'] ?? 0),
            'origQty' => (float) ($response['origQty'] ?? 0),
        ];
    }

    public function createListenKey(): string
    {
        $response = $this->apiKeyRequest('POST', '/fapi/v1/listenKey');

        return (string) ($response['listenKey'] ?? '');
    }

    public function keepAliveListenKey(): void
    {
        $this->apiKeyRequest('PUT', '/fapi/v1/listenKey');
    }

    public function closeListenKey(): void
    {
        $this->apiKeyRequest('DELETE', '/fapi/v1/listenKey');
    }

    /**
     * Make an API-key-authenticated (but unsigned) request.
     * Used by listenKey endpoints, which require the API key header but no HMAC signature.
     */
    private function apiKeyRequest(string $method, string $endpoint): array
    {
        $this->checkRateLimit();

        $url = $this->baseUrl . $endpoint;

        $response = Http::timeout(10)
            ->withHeaders(['X-MBX-APIKEY' => $this->apiKey])
            ->$method($url);

        $this->trackRateLimit($response);

        if ($response->failed()) {
            Log::error('Binance listenKey API error', [
                'endpoint' => $endpoint,
                'method' => $method,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException("Binance listenKey error: {$response->body()}");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
