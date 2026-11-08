<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Illuminate\Console\Command;
use Kanvas\Scribe\FxRates\Actions\SyncCentralBankRatesAction;
use Throwable;

/**
 * Platform rows only (apps_id 0, the official rate is the same for every tenant), so no app is bound.
 */
class SyncFxRatesCommand extends Command
{
    protected $signature = 'scribe:sync-fx-rates';

    protected $description = 'Store the Banco Central de la República Dominicana buy rate for each foreign invoice currency.';

    public function handle(): int
    {
        try {
            $rates = new SyncCentralBankRatesAction()->execute();
        } catch (Throwable $e) {
            report($e);
            $this->error('FX sync failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($rates as $rate) {
            $this->info("{$rate->base_currency}→{$rate->quote_currency} {$rate->rate} for {$rate->rate_date->toDateString()} ({$rate->source})");
        }

        if ($rates === []) {
            $this->warn('The provider returned no rate.');
        }

        return self::SUCCESS;
    }
}
