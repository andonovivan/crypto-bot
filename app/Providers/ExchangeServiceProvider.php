<?php

namespace App\Providers;

use App\Services\Exchange\BinanceExchange;
use App\Services\Exchange\DryRunExchange;
use App\Services\Exchange\ExchangeDispatcher;
use App\Services\Exchange\ExchangeInterface;
use Illuminate\Support\ServiceProvider;

class ExchangeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExchangeInterface::class, function () {
            $binance = new BinanceExchange();

            return new ExchangeDispatcher(
                live: $binance,
                dry: new DryRunExchange($binance),
            );
        });
    }
}
