<?php

namespace App\Console\Commands;

use App\Services\PumpScanner;
use App\Services\TradingEngine;
use Illuminate\Console\Command;

class ScanPumps extends Command
{
    protected $signature = 'bot:scan {--trade : Automatically open shorts on confirmed reversals}';
    protected $description = 'Scan for pumped coins and detect reversal signals';

    public function handle(PumpScanner $scanner, TradingEngine $engine): int
    {
        $this->info('Scanning for pumped coins...');

        // Scan for new pump signals
        $signals = $scanner->scan();
        $this->info("Found {$signals->count()} pump signal(s)");

        foreach ($signals as $signal) {
            $this->line("  {$signal->symbol}: +{$signal->price_change_pct}% | Vol: {$signal->volume_multiplier}x | Drop: {$signal->drop_from_peak_pct}%");
        }

        // Check existing signals for reversals
        $reversals = $scanner->checkReversals();
        $this->info("Confirmed {$reversals->count()} reversal(s)");

        foreach ($reversals as $signal) {
            $this->warn("  REVERSAL: {$signal->symbol} dropped {$signal->drop_from_peak_pct}% from peak");

            if ($this->option('trade')) {
                $position = $engine->openShort($signal);
                if ($position) {
                    $this->info("  -> Opened SHORT @ {$position->entry_price} | SL: {$position->stop_loss_price} | TP: {$position->take_profit_price}");
                }
            }
        }

        // Clean up old signals
        $expired = $scanner->expireStaleSignals();
        if ($expired > 0) {
            $this->line("Expired {$expired} stale signal(s)");
        }

        return self::SUCCESS;
    }
}
