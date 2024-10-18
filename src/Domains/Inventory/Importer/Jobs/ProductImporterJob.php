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
use Nuwave\Lighthouse\Execution\Utils\Subscription;

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
    public $uniqueFor = 0;

    public function __construct(
        public string $jobUuid,
        public array $importer,
        public CompaniesBranches $branch,
        public UserInterface $user,
        public Regions $region,
        public AppInterface $app,
        public ?FilesystemImports $filesystemImport = null,
        public bool $runWorkflow = true
    ) {
        $minuteDelay = (int)($app->get('delay_minute_job') ?? 0);
        $queue = $this->onQueue('imports');
        if ($minuteDelay) {
            $queue->delay(now()->addMinutes($minuteDelay));
        }

        $minuteUniqueFor = (int)($app->get('unique_for_minute_job') ?? 1);
        if (App::environment('production')) {
            $this->uniqueFor = $minuteUniqueFor * 60;
        }
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        // Create a unique hash of the importer array
        $importerHash = md5(json_encode($this->importer));

        return $this->app->getId() . $this->branch->getId() . $this->region->getId() . $importerHash;
    }

    public function middleware(): array
    {
        if (! $this->uniqueFor) {
            return [];
        }

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

        /**
         * @var Companies
         */
        $company = $this->branch->company()->firstOrFail();
        $totalItems = count($this->importer);
        $totalProcessSuccessfully = 0;
        $totalProcessFailed = 0;
        $created = 0;
        $updated = 0;
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
                $product = (new ProductImporterAction(
                    ProductImporter::from($request),
                    $company,
                    $this->user,
                    $this->region,
                    $this->app,
                    $this->runWorkflow
                ))->execute();
                if ($product->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
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
        $this->notificationStatus($totalItems, $totalProcessSuccessfully, $totalProcessFailed, $created, $updated, $errors, $company);
        //handle failed jobs
    }

    protected function notificationStatus(
        int $totalItems,
        int $totalProcessSuccessfully,
        int $totalProcessFailed,
        int $created,
        int $updated,
        array $errors,
        Companies $company
    ): void {
        $subscriptionData = [
                   'jobUuid' => $this->jobUuid,
                   'status' => 'completed',
                   'results' => [
                       'total_items' => $totalItems,
                       'total_process_successfully' => $totalProcessSuccessfully,
                       'total_process_failed' => $totalProcessFailed,
                       'created' => $created,
                       'updated' => $updated,
                   ],
                   'exception' => $errors,
                   'user' => $this->user,
                   'company' => $company,
               ];
        Subscription::broadcast('filesystemImported', $subscriptionData);
    }
}
