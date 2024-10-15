<?php

namespace Kanvas\Inventory\Importer\Handlers;

use Kanvas\Inventory\Importer\Jobs\SingleProductImporterJob;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Inventory\Regions\Models\Regions;
use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Bus\Dispatchable;
use Kanvas\Filesystem\Models\FilesystemImports;
use Illuminate\Support\Facades\Log;
use Baka\Support\Str;
use Throwable;
use Exception;
use Illuminate\Bus\Batch;

class ProductsImporterBatchHandler
{
    public function __construct(
        public array $imports,
        public CompaniesBranches $branch,
        public UserInterface $user,
        public Regions $region,
        public AppInterface $app,
        public ?FilesystemImports $filesystemImport = null
    ) {
    }

    /**
     * Add products to a batch
     *
     * @return void
     */
    public function process()
    {
        $totalProcessSuccessfully = 0;
        $totalProcessFailed = 0;
        $errors = [];
        $batch = Bus::batch([]);

        foreach ($this->imports as $request) {
            $batch->add(
                new SingleProductImporterJob(
                    // Str::uuid()->toString(),
                    $request,
                    $this->branch,
                    $this->user,
                    $this->region,
                    $this->app
                )
            );
        }

        $batch->then(function (Batch $batch) {
            Log::error("All jobs from batch with ID: " . $batch->id . " are done");
        })->allowFailures()->onQueue('imports')->dispatch();

        if ($this->filesystemImport) {
            $this->filesystemImport->update([
                'results' => [
                    'total_items' => $batch->totalJobs,
                    'total_process_successfully' => $batch->totalJobs - $batch->failedJobs,
                    'total_process_failed' => $batch->failedJobs,
                ],
                'exception' => $errors,
                'status' => 'completed',
                'finished_at' => now(),
            ]);
        }
    }
}
