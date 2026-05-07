<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Elead;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Support\ApiCallTracker;
use Kanvas\Connectors\Elead\Support\EleadCache;

class FlushEleadCacheCommand extends Command
{
    protected $signature = 'kanvas:elead-flush-cache {app_id} {company_id}';

    protected $description = 'Flush all Elead cached data (entities, reference data) for a company';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));

        $cache = new EleadCache($app, $company);
        $deleted = $cache->flushAll();

        $this->info("Flushed {$deleted} Elead cache keys for company {$company->name} (ID: {$company->getId()})");

        $tracker = new ApiCallTracker($app, $company);
        $this->info("Today's API usage: {$tracker->getDailyCount()} calls, {$tracker->getDailyErrors()} errors (limit: {$tracker->getDailyLimit()})");
    }
}
