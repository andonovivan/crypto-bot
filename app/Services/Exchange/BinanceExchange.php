<?php

namespace App\Services\Exchange;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BinanceExchange implements ExchangeInterface
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;

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

        return $tickers;
    }

    public function getPrice(string $symbol): float
    {
        $response = $this->publicRequest('GET', '/fapi/v1/ticker/price', [
            'symbol' => $symbol,
        ]);

        return (float) $response['price'];
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

    public function openShort(string $symbol, float $quantity): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/order', [
            'symbol' => $symbol,
            'side' => 'SELL',
            'type' => 'MARKET',
            'quantity' => $this->formatQuantity($quantity),
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
            'quantity' => $this->formatQuantity($quantity),
            'reduceOnly' => 'true',
        ]);

        return [
            'orderId' => (string) $response['orderId'],
            'price' => (float) ($response['avgPrice'] ?? $response['price'] ?? 0),
            'quantity' => (float) $response['executedQty'],
        ];
    }

    public function setStopLoss(string $symbol, float $stopPrice, float $quantity): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/order', [
            'symbol' => $symbol,
            'side' => 'BUY',
            'type' => 'STOP_MARKET',
            'stopPrice' => $this->formatPrice($stopPrice),
            'quantity' => $this->formatQuantity($quantity),
            'reduceOnly' => 'true',
        ]);

        return ['orderId' => (string) $response['orderId']];
    }

    public function setTakeProfit(string $symbol, float $takeProfitPrice, float $quantity): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/order', [
            'symbol' => $symbol,
            'side' => 'BUY',
            'type' => 'TAKE_PROFIT_MARKET',
            'stopPrice' => $this->formatPrice($takeProfitPrice),
            'quantity' => $this->formatQuantity($quantity),
            'reduceOnly' => 'true',
        ]);

        return ['orderId' => (string) $response['orderId']];
    }

    public function getBalance(): float
    {
        $response = $this->signedRequest('GET', '/fapi/v2/balance');

        foreach ($response as $asset) {
            if ($asset['asset'] === 'USDT') {
                return (float) $asset['availableBalance'];
            }
        }

        return 0.0;
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

    public function calculateQuantity(string $symbol, float $usdtAmount, float $price): float
    {
        if ($price <= 0) {
            return 0;
        }

        $quantity = $usdtAmount / $price;

        return $this->roundStepSize($quantity, $symbol);
    }

    /**
     * Make a public (unsigned) API request.
     */
    private function publicRequest(string $method, string $endpoint, array $params = []): array
    {
        $url = $this->baseUrl . $endpoint;

        $response = Http::timeout(10)
            ->withHeaders(['X-MBX-APIKEY' => $this->apiKey])
            ->$method($url, $params);

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

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.');
    }

    private function formatPrice(float $price): string
    {
        return rtrim(rtrim(number_format($price, 8, '.', ''), '0'), '.');
    }

    /**
     * Round quantity to the nearest valid step size.
     * For simplicity, we round to 3 decimal places. A production bot would
     * fetch exchangeInfo and use the actual LOT_SIZE filter per symbol.
     */
    private function roundStepSize(float $quantity, string $symbol): float
    {
        return round($quantity, 3);
    }
}
