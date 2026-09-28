<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Metrics;

use Illuminate\Console\Command;
use Kanvas\Intelligence\Agents\Actions\SyncModelPricingAction;
use Throwable;

use function Sentry\captureException;

class SyncModelPricingCommand extends Command
{
    /**
     * Pulls LLM model pricing from LiteLLM (primary) / OpenRouter (fallback)
     * and versions any rate changes into `model_pricing`. Wired daily in
     * Kernel.php — never break the cost write path if upstream is down,
     * just log and bail; existing snapshots stay correctly costed against
     * the last-known-good row.
     */
    protected $signature = 'nervous-system:sync-model-pricing';

    protected $description = 'Sync LLM model pricing from LiteLLM / OpenRouter into the model_pricing table.';

    public function handle(): int
    {
        $this->info('Syncing model pricing from upstream…');

        try {
            $action = new SyncModelPricingAction();
            $result = $action->execute();
        } catch (Throwable $e) {
            captureException($e);
            $this->error('Sync failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  source=%s  inserted=%d  versioned=%d  unchanged=%d  skipped=%d',
            $result['source'],
            $result['inserted'],
            $result['versioned'],
            $result['unchanged'],
            $result['skipped'],
        ));
        $this->info('Done.');

        return self::SUCCESS;
    }
}
