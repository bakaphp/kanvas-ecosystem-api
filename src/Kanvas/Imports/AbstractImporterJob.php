<?php

declare(strict_types=1);

namespace Kanvas\Imports;

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
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Inventory\Regions\Models\Regions;

abstract class AbstractImporterJob implements ShouldQueue, ShouldBeUnique
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
    abstract public function handle();

    protected function startFilesystemMapperImport(): void
    {
        if ($this->filesystemImport) {
            $this->filesystemImport->update([
                'status' => 'processing',
            ]);
        }
    }

    protected function finishFilesystemMapperImport(
        int $totalItems,
        int $totalProcessSuccessfully,
        int $totalProcessFailed,
        array $errors
    ): void {
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
    }

    abstract protected function notificationStatus(
        int $totalItems,
        int $totalProcessSuccessfully,
        int $totalProcessFailed,
        int $created,
        int $updated,
        array $errors,
        Companies $company
    ): void ;
}
