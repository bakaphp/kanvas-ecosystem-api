<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Contracts\DiscoveryIndexAdapter;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Services\DiscoveryIndexAdapterResolverService;

/**
 * Gate for the range filters. Until every record of the app carries the current schema version, a
 * range filter on a field an old record lacks would drop that record from results, so the app setting
 * that turns the filters on is only written once the index proves it is complete.
 */
class VerifyDiscoveryIndexCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * @var string
     */
    protected $signature = 'kanvas-inventory:verify-discovery-index
                            {app_id : App whose product index to verify}';

    /**
     * @var string
     */
    protected $description = 'Check every record of the app is on the current discovery schema and, if so, switch range filters on';

    public function handle(DiscoveryIndexAdapterResolverService $adapters): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $adapter = $adapters->resolve($app);

        if ($adapter === null) {
            $this->info('Nothing to verify on the SQL fallback.');

            return self::SUCCESS;
        }

        $version = Products::DISCOVERY_SCHEMA_VERSION;
        $coverage = $adapter->coverage($version);
        $unmigrated = $coverage['total'] - $coverage['migrated'];
        $inDatabase = Products::fromApp($app)->notDeleted()->where('is_published', 1)->count();

        $this->line("Records in the index for this app: {$coverage['total']}");
        $this->line("On schema version >= {$version}: {$coverage['migrated']}");
        $this->line("Published products in the database: {$inDatabase}");

        if ($inDatabase > $coverage['total']) {
            $this->warn(($inDatabase - $coverage['total']) . ' published product(s) are not in the index at all.');
        }

        if ($unmigrated > 0) {
            $this->warn("{$unmigrated} record(s) are not on the current schema; reindex and run this again.");

            if ($coverage['stale_ids'] !== []) {
                $this->line('Records on an older version (up to ' . DiscoveryIndexAdapter::STALE_ID_LIMIT . '): ' . implode(', ', $coverage['stale_ids']));
            }

            $this->line('Records missing the version field entirely are counted above but cannot be listed.');
        }

        if ($coverage['total'] === 0 || $unmigrated > 0) {
            return self::FAILURE;
        }

        $app->set(ConfigurationEnum::RANGE_FILTERS->value, $version);
        $this->info("Range filters verified for schema version {$version} and switched on.");

        return self::SUCCESS;
    }
}
