<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Actions;

use Kanvas\Connectors\ScrapingDog\Jobs\ScraperJob;
use Kanvas\Connectors\ScrapingDog\Repositories\ScrapingDogRepository;
use Kanvas\Connectors\ScrapperApi\Actions\ScrapperAction as ScraperApiAction;
use Laravel\Octane\Facades\Octane;

class ScraperAction extends ScraperApiAction
{
    // @todo: abstract the logic to a base class, we can reuse this because the repository and processor have same methods
    public function execute(): array
    {
        $repository = new ScrapingDogRepository($this->app);
        $results = $repository->getSearch($this->search)['results'] ?? [];
        $scraperProducts = 0;
        $importerProducts = 0;
        $limit = (int) $this->app->get('limit-product-scraper');
        $firstGroup = array_slice($results, 0, $limit);
        $secondGroup = array_slice($results, $limit);
        $app = $this->app;
        $user = $this->user;
        $companyBranch = $this->companyBranch;
        $region = $this->region;
        $uuid = $this->uuid;
        $classConcurrently = [];
        foreach ($firstGroup as $result) {
            $action = (new ScraperProcessorAction(
                $app,
                $user,
                $companyBranch,
                $region,
                [$result],
                null,
                $this->search,
                $this->cacheKey
            ));
            // $classConcurrently[] = fn () => $action->execute();
            $action->execute();
        }
        // $resultsOctane = Octane::concurrently($classConcurrently, 1000000);

        ScraperJob::dispatch(
            $app,
            $user,
            $companyBranch,
            $region,
            $secondGroup,
            $uuid,
            $this->search,
            $this->cacheKey
        );
        sleep(4);


        return [
            'scrapperProducts' => 5,
            'importerProducts' => $importerProducts,
            'results' => 1,
        ];
    }
}
