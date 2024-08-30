<?php

declare(strict_types=1);

namespace Kanvas\ImportersRequests\Actions;

use Kanvas\ImportersRequests\Models\ImporterRequest;
use Kanvas\ImportersRequests\DataTransferObject\ImporterRequest as ImporterRequestDto;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob as ImporterJob;
use Kanvas\Apps\Models\Apps;

class LegacyImportAction
{
    public function __construct(
        private ImporterRequest $importerRequest
    ) {
    }

    public function execute()
    {
        //verify it has the correct format
        ProductImporter::from($this->importerRequest->data);

        //so we can tie the job to pusher
        ImporterJob::dispatch(
            $this->importerRequest->uuid,
            $this->importerRequest->data,
            $this->importerRequest,
            auth()->user(),
            $this->importerRequest->region,
            app(Apps::class)
        );
    }
}
