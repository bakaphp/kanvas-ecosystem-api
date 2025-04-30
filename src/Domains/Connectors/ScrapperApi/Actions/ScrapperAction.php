<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Laravel\Octane\Facades\Octane;

/**
 * Class ScrapperAction.
 */
class ScrapperAction
{
    public ?string $uuid = null;

    public function __construct(
        public AppInterface $app,
        public Users $user,
        public CompaniesBranches $companyBranch,
        protected Regions $region,
        public string $search,
        ?string $uuid = null
    ) {
        $this->uuid = $uuid;
    }

    public function execute(): array
    {
        $repository = new ScrapperRepository($this->app);
        $results = $repository->getSearch($this->search);
        $scrapperProducts = 0;
        $importerProducts = 0;
        $limit = (int) $this->app->get('limit-product-scrapper');
        $results = array_slice($results, 0, $limit);
        $app = $this->app;
        $user = $this->user;
        $companyBranch = $this->companyBranch;
        $region = $this->region;
        $uuid = $this->uuid;
        $classConcurrently = [];
        foreach ($results as $result) {
            $action = (new ScrapperProcessorAction(
                $app,
                $user,
                $companyBranch,
                $region,
                [$result],
                $uuid
            ));
            $classConcurrently[] = fn () => $action->execute();
        }
        $resultsOctane = Octane::concurrently($classConcurrently,60000);
        return [
            'scrapperProducts' => $scrapperProducts,
            'importerProducts' => $importerProducts,
            'results' => $results
        ];
    }
}
