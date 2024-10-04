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

class ProductImporterBatchHandler
{
    private $batchId;

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
    public function add()
    {
        $batch = Bus::batch([])->dispatch();

        foreach ($this->imports as $request) {
            $batch->add(
                new SingleProductImporterJob(
                    Str::uuid()->toString(),
                    $request,
                    $this->branch,
                    $this->user,
                    $this->region,
                    $this->app
                )
            );
        }

        $this->batchId = $batch->id;
        
    }

    /**
     * Dispatch batch by batchId
     * 
     */
    private function dispatch(string $batchId = null): void
    {
        if (!$batchId) {
            $batchId = $this->batchId;
        }
        
        if ($this->filesystemImport) {
            $this->filesystemImport->update([
                'status' => 'processing', //move to enums
            ]);
        }

        $totalProcessSuccessfully = 0;
        $totalProcessFailed = 0;
        $errors = [];

        // Retrieve the batch using its ID and dispatch it
        $batch = Bus::findBatch($batchId);
        if ($batch) {
            $batch->progress(function ($totalProcessSuccessfully) {
                $totalProcessSuccessfully++;
            })->catch(function ($totalProcessFailed, Throwable $e) {
                $errors[] = [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    // 'request' => $this->request,
                ];
                Log::error($e->getMessage());
                // captureException($e);
                $totalProcessFailed++;
            })->dispatch();

            if ($this->filesystemImport) {
                $this->filesystemImport->update([
                    'results' => [
                        'total_items' => $batch->totalJobs,
                        'total_process_successfully' => $totalProcessSuccessfully,
                        'total_process_failed' => $totalProcessFailed,
                    ],
                    'exception' => $errors,
                    'status' => 'completed',
                    'finished_at' => now(),
                ]);
            }
        } else {
            throw new Exception("Batch not found.");
        }

    }

    
}
