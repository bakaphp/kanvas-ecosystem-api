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
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;

use function Sentry\captureException;

use Throwable;

class ProductImporterJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    /**
    * The number of seconds after which the job's unique lock will be released.
    *
    * @var int
    */
    public $uniqueFor = 1800;

    public function __construct(
        public string $jobUuid,
        public array $importer,
        public CompaniesBranches $branch,
        public UserInterface $user,
        public Regions $region,
        public AppInterface $app,
        public ?FilesystemImports $filesystemImport = null
    ) {
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->jobUuid . $this->app->getId() . $this->region->getId() . $this->branch->getId();
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
        $totalItems = count($this->importer);
        $totalProcessSuccessfully = 0;
        $totalProcessFailed = 0;
        $errors = [];

        //mark all variants as unsearchable for this company before running the import
        /*         Variants::fromCompany($company)->chunkById(100, function ($variants) {
                    $variants->unsearchable();
                }, $column = 'id'); */

        if ($this->filesystemImport) {
            $this->filesystemImport->update([
                'status' => 'processing', //move to enums
            ]);
        }

        foreach ($this->importer as $request) {
            try {
                (new ProductImporterAction(
                    ProductImporter::from($request),
                    $company,
                    $this->user,
                    $this->region,
                    $this->app
                ))->execute();
                $totalProcessSuccessfully++;
            } catch (Throwable $e) {
                $errors[] = [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'request' => $request,
                ];
                Log::error($e->getMessage());
                captureException($e);
                $totalProcessFailed++;
            }
        }

        if ($this->filesystemImport) {
            $this->filesystemImport->update([
                'results' => [
                    'total_items' => $totalItems,
                    'total_process_successfully' => $totalProcessSuccessfully,
                    'total_process_failed' => $totalProcessFailed,
                ],
                'exception' => $errors,
                'status' => 'completed',
                'finished_at' => now(),
            ]);
        }

        //handle failed jobs
    }
}
