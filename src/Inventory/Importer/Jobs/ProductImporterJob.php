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
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter as ImporterDto;
use Kanvas\Inventory\Regions\Models\Regions;

class ProductImporterJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    /**
     * constructor.
     *
     * @param string $jobUuid
     * @param array<int, ImporterDto> $importer
     * @param CompaniesBranches $branch
     * @param UserInterface $user
     * @param Regions $region
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
        Auth::loginUsingId($this->user->getId());
        $this->overwriteAppService($this->app);
        $this->overwriteAppServiceLocation($this->branch);

        /**
         * @var Companies
         */
        $company = $this->branch->company()->firstOrFail();

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
