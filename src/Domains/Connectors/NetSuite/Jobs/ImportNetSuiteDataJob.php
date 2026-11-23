<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\ImportNetSuiteDataAction;
use Kanvas\Connectors\NetSuite\Enums\ImportEntityEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteImportStatusService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;
use RuntimeException;
use Throwable;

/**
 * One chunk of a NetSuite import per job, each queuing the next. A catalog pulled one record at a
 * time through a two-slot NetSuite semaphore runs for hours, past any worker timeout, while a
 * chunk finishes in minutes. Stock is one search per location and runs whole.
 */
class ImportNetSuiteDataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const int CHUNK_SIZE = 100;

    public int $tries = 1;
    public int $timeout = 3600;

    public function __construct(
        public Apps $app,
        public Companies $company,
        public Users $user,
        public ImportEntityEnum $entity,
        public ?string $savedSearchId = null,
        public bool $createMissing = false,
        public int $offset = 0,
        public array $locationIds = []
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $status = new NetSuiteImportStatusService($this->company, $this->entity);
        $action = $this->action();

        if ($this->entity === ImportEntityEnum::STOCK) {
            $status->start(null);
            $status->complete($action->importStock(
                $this->savedSearchId ?? throw new RuntimeException('A NetSuite stock import needs a saved search.'),
                $this->createMissing
            ));

            return;
        }

        $ids = $this->offset === 0 ? $this->listIds($action, $status) : $status->ids();

        if ($ids === null) {
            throw new RuntimeException('The NetSuite ' . $this->entity->value . ' import lost its record list. Start the import again.');
        }

        $chunk = array_slice($ids, $this->offset, self::CHUNK_SIZE);

        if ($chunk !== []) {
            $status->advance($action->importRecords($this->entity, $chunk, $this->locationIds));
        }

        $next = $this->offset + self::CHUNK_SIZE;

        if ($next >= count($ids)) {
            $status->complete($this->queueStockForTheOtherLocations());

            return;
        }

        dispatch(new self(
            $this->app,
            $this->company,
            $this->user,
            $this->entity,
            $this->savedSearchId,
            $this->createMissing,
            $next,
            $this->locationIds
        ));
    }

    public function failed(Throwable $exception): void
    {
        new NetSuiteImportStatusService($this->company, $this->entity)->fail($exception->getMessage());
    }

    protected function action(): ImportNetSuiteDataAction
    {
        return new ImportNetSuiteDataAction($this->app, $this->company, $this->user);
    }

    /**
     * A product's refresh brings the quantity of the first chosen location only. The others come
     * from one stock search per location, far cheaper than a call per product and location.
     */
    private function queueStockForTheOtherLocations(): ?array
    {
        if ($this->entity !== ImportEntityEnum::PRODUCTS || count($this->locationIds) < 2) {
            return null;
        }

        $stock = new NetSuiteImportStatusService($this->company, ImportEntityEnum::STOCK);

        if ($stock->isInProgress()) {
            return ['stock' => 'skipped', 'reason' => 'A NetSuite stock import is already in progress.'];
        }

        try {
            $savedSearchId = ImportEntityEnum::STOCK->resolveSavedSearchId($this->app, $this->company, null);
        } catch (ValidationException $e) {
            return ['stock' => 'skipped', 'reason' => $e->getMessage()];
        }

        $stock->queue($savedSearchId);
        dispatch(new self(
            $this->app,
            $this->company,
            $this->user,
            ImportEntityEnum::STOCK,
            $savedSearchId
        ));

        return ['stock' => 'queued'];
    }

    /**
     * @return list<string>
     */
    private function listIds(ImportNetSuiteDataAction $action, NetSuiteImportStatusService $status): array
    {
        $ids = $action->listIds($this->entity, $this->savedSearchId, $this->locationIds);
        $status->rememberIds($ids);
        $status->start(count($ids));

        return $ids;
    }
}
