<?php

namespace App\Services\Strategy;

use App\Services\Settings;

/**
 * Optional convenience base class for strategy implementations. Provides
 * namespaced settings access so a strategy doesn't have to hardcode its
 * key prefix on every Settings::get() call.
 */
abstract class AbstractStrategy implements StrategyInterface
{
    public function isEnabled(): bool
    {
        return (bool) Settings::get('strategy.'.$this->key().'.enabled');
    }

    /**
     * Read a setting under this strategy's namespace. Equivalent to
     * Settings::get('strategy.'.$this->key().'.'.$key). Returns null if
     * neither override, DB row, nor config default exists.
     */
    protected function setting(string $key): mixed
    {
        return Settings::get('strategy.'.$this->key().'.'.$key);
    }
}
