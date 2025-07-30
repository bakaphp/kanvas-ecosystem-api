<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Jobs;

use Kanvas\Connectors\ScrapingDog\Actions\ScraperProcessorAction;
use Kanvas\Connectors\ScrapperApi\Jobs\ScrapperJob;

class ScraperJob extends ScrapperJob
{
    /**
     * Handle the job.
     *
     * @return void
     */
    public function handle()
    {
        new ScraperProcessorAction(
            $this->app,
            $this->user,
            $this->companyBranch,
            $this->region,
            $this->results,
            $this->uuid,
            $this->searchText
        )->execute();
    }
}
