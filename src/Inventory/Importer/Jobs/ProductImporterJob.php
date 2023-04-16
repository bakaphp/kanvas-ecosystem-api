<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Inventory\Enums\AppEnums;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter as ImporterDto;
use Kanvas\Inventory\Regions\Models\Regions;
use Laravel\Scout\EngineManager;

class ProductImporterJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;
    public $failOnTimeout = false;
    public $timeout = 120000;

    /**
     * constructor.
     *
     * @param array<int, ImporterDto> $importer
     */
    public function __construct(
        public string $jobUuid,
        public array $importer,
        public CompaniesBranches $branch,
        public UserInterface $user,
        public Regions $region,
        public AppInterface $app
    ) {
    }

    /**
     * handle.
     *
     * @return void
     */
    public function handle()
    {
        config(['laravel-model-caching.disabled' => true]);
        Auth::loginUsingId($this->user->getId());
        $this->overwriteAppService($this->app);
        $this->overwriteAppServiceLocation($this->branch);

        /**
         * @var Companies
         */
        $company = $this->branch->company()->firstOrFail();

        /**
         * @var \Laravel\Scout\Engines\MeiliSearchEngine
         */
        $meiliSearchEngine = app(EngineManager::class)->engine();

        /**
         * @todo
         * right now we are cleaning the index for the company but we have a issue
         * this index is for all variants for a given company , but the search function
         * is looking for variant of a given public channel
         * so we need to move the index to be specific of the channel we are importing
         * to avoid future issues
         */
        $meiliSearchEngine->deleteIndex(
            (string) AppEnums::PRODUCT_VARIANTS_SEARCH_INDEX->getValue() . $company->getId()
        );

        foreach ($this->importer as $request) {
            (new ProductImporterAction(
                ProductImporter::from($request),
                $company,
                $this->user,
                $this->region,
                $this->app
            ))->execute();
        }
    }
}
