<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Jobs;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\Companies;
use Kanvas\Imports\AbstractImporterJob;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Importer\Events\ProductImportEvent;
use Kanvas\Inventory\Variants\Models\Variants;
use Nuwave\Lighthouse\Execution\Utils\Subscription;

use function Sentry\captureException;

use Throwable;

class ProductImporterJob extends AbstractImporterJob
{
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

        $this->startFilesystemMapperImport();

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

                //handle failed jobs
            } catch (Throwable $e) {
                $errorDetails = [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'request' => $request,
                ];
                $errors[] = $errorDetails;
                Log::error($e->getMessage(), $errorDetails);
                captureException($e);
                $totalProcessFailed++;
            }
        }

        $this->finishFilesystemMapperImport(
            $totalItems,
            $totalProcessSuccessfully,
            $totalProcessFailed,
            $errors
        );

        $this->notificationStatus(
            $totalItems,
            $totalProcessSuccessfully,
            $totalProcessFailed,
            $created,
            $updated,
            $errors,
            $company
        );
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
                   //'user' => $this->user,
                  // 'company' => $company,
               ];

        ProductImportEvent::dispatch(
            $this->app,
            $this->branch->company,
            $this->user,
            $subscriptionData
        );
        Subscription::broadcast('filesystemImported', $subscriptionData);
    }
}
