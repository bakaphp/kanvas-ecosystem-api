<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Recommendations\Services\DiscoveryIndexAdapterResolverService;

/**
 * First step of moving an app's index to the current discovery fields: it declares what the engine
 * needs to accept them. Records only gain the values after the reindex that follows, and
 * verify-discovery-index is what confirms they did.
 */
class PrepareDiscoveryIndexCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * @var string
     */
    protected $signature = 'kanvas-inventory:prepare-discovery-index
                            {app_id : App whose product index to prepare}
                            {--dry-run : Print what would change and write nothing}
                            {--from-default : Write the proposed searchableAttributes when the Algolia index has none}';

    /**
     * @var string
     */
    protected $description = 'Declare the discovery fields on the app product index without removing or reordering anything';

    public function handle(DiscoveryIndexAdapterResolverService $adapters): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $adapter = $adapters->resolve($app);

        if ($adapter === null) {
            $this->info('Nothing to prepare on the SQL fallback.');

            return self::SUCCESS;
        }

        $fromDefault = (bool) $this->option('from-default');
        $plan = $adapter->ensureFields(dryRun: true, fromDefault: $fromDefault);
        $outcome = $plan['refusal'] === null ? self::SUCCESS : self::FAILURE;

        $this->info('Index: ' . $plan['index']);

        foreach ($plan['changes'] as $change) {
            $this->line('  + ' . $change);
        }

        if ($plan['refusal'] !== null) {
            $this->warn($plan['refusal']);
            $this->line('  Proposed searchableAttributes: ' . implode(', ', $plan['proposed']));
        }

        if ($plan['changes'] === []) {
            $this->info($plan['refusal'] === null ? 'Already up to date.' : 'Nothing else to change.');

            return $outcome;
        }

        if ($this->option('dry-run')) {
            return $outcome;
        }

        if ($this->input->isInteractive() && ! $this->confirm('Write these changes to the index?')) {
            $this->warn('Nothing written.');

            return self::FAILURE;
        }

        $adapter->ensureFields(dryRun: false, fromDefault: $fromDefault);
        $this->info('Written. Next: reindex, then kanvas-inventory:verify-discovery-index ' . $app->getId());

        return $outcome;
    }
}
