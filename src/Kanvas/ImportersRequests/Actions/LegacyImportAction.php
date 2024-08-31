<?php

declare(strict_types=1);

namespace Kanvas\ImportersRequests\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\ImportersRequests\Models\ImporterRequest;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob as ImporterJob;

class LegacyImportAction
{
    public function __construct(
        protected UserInterface $user,
        protected AppInterface $app,
        protected ImporterRequest $importerRequest
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
            $this->user,
            $this->importerRequest->region,
            $this->app
        );
    }
}
