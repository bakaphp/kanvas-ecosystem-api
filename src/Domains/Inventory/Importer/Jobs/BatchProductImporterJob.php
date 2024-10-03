<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Batchable;
use Kanvas\Inventory\Importer\Jobs\SingleProductImporterJob;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Inventory\Regions\Models\Regions;
use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

use function Sentry\captureException;

use Throwable;

class BatchProductImporterJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    use Batchable;

    public function __construct(
        public string $jobUuid,
        public array $request,
        public CompaniesBranches $branch,
        public UserInterface $user,
        public Regions $region,
        public AppInterface $app,
        public ?FilesystemImports $filesystemImport = null
    ) {
    }

    /**
     * handle.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->filesystemImport) {
            $this->filesystemImport->update([
                'status' => 'processing', //move to enums
            ]);
        }

        $totalProcessSuccessfully = 0;
        $totalProcessFailed = 0;
        $errors = [];

        $this->batch()->add(
            new SingleProductImporterJob(
                $this->jobUuid,
                $this->request,
                $this->branch,
                $this->user,
                $this->region,
                $this->app
            )
        )->progress(function ($totalProcessSuccessfully) {
            $totalProcessSuccessfully++;
        })->catch(function ($totalProcessFailed, Throwable $e) {
            $errors[] = [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $this->request,
            ];
            Log::error($e->getMessage());
            captureException($e);
            $totalProcessFailed++;
        })->dispatch();

        if ($this->filesystemImport) {
            $this->filesystemImport->update([
                'results' => [
                    'total_items' => $this->batch()->totalJobs,
                    'total_process_successfully' => $totalProcessSuccessfully,
                    'total_process_failed' => $totalProcessFailed,
                ],
                'exception' => $errors,
                'status' => 'completed',
                'finished_at' => now(),
            ]);
        }
    }
}
