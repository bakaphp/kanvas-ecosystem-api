<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Enums\ConfigEnum as ScrapperConfigEnum;
use Kanvas\Connectors\ScrapperApi\Events\ProductScrapperEvent;
use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;
use Kanvas\Connectors\ScrapperApi\Services\ProductService;
use Kanvas\Connectors\Shopify\Actions\SyncProductWithShopifyAction;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use PHPShopify\Exception\CurlException;
use Laravel\Octane\Facades\Octane;

use function Sentry\captureException;

use Throwable;

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
        Log::info('Scrapper Started');
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
        Log::debug(json_encode(value: $classConcurrently));
        Octane::concurrently($classConcurrently);
        return [
            'scrapperProducts' => $scrapperProducts,
            'importerProducts' => $importerProducts,
            'results' => $results
        ];
    }
}
