<?php

namespace App\Providers;

use App\Services\Strategy\StrategyInterface;
use App\Services\Strategy\StrategyRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Builds the StrategyRegistry singleton from config/strategies.php.
 * Each declared class is resolved via the container so strategies can
 * inject their own dependencies (scanners, exchanges, etc.) — wiring
 * them up exactly like ShortScanner is wired today.
 *
 * Registered late enough that strategies can themselves type-hint the
 * registry if they need to consult sibling strategies in the future.
 */
class StrategyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StrategyRegistry::class, function ($app) {
            $config = $app['config'];
            $classes = $config->get('strategies.classes', []);
            $order = $config->get('strategies.order', []);

            $instances = [];
            foreach ($classes as $key => $class) {
                $instance = $app->make($class);
                if (! $instance instanceof StrategyInterface) {
                    throw new \RuntimeException(sprintf(
                        'Strategy class %s must implement StrategyInterface (got %s).',
                        $class,
                        is_object($instance) ? $instance::class : gettype($instance),
                    ));
                }
                $instances[$key] = $instance;
            }

            return new StrategyRegistry($instances, $order);
        });
    }
}
