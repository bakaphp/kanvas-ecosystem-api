<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Illuminate\Bus\Batchable;

use function Sentry\captureException;

use Throwable;

class SingleProductImporterJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;
    use Batchable;

    /**
    * The number of seconds after which the job's unique lock will be released.
    *
    * @var int
    */
    public $uniqueFor = 60;

    public function __construct(
        public array $request,
        public CompaniesBranches $branch,
        public UserInterface $user,
        public Regions $region,
        public AppInterface $app,
    ) {
        $this->onQueue('imports');

        if (App::environment('production')) {
            $this->uniqueFor = 15 * 60;
        }
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        // Create a unique hash of the importer array
        $importerHash = md5(json_encode($this->request));

        return $this->app->getId() . $this->branch->getId() . $this->region->getId() . $importerHash;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))->expireAfter($this->uniqueFor),
        ];
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
        $company = $this->branch->company()->firstOrFail();

        (new ProductImporterAction(
            ProductImporter::from($this->request),
            $company,
            $this->user,
            $this->region,
            $this->app
        ))->execute();
        
    }
}
