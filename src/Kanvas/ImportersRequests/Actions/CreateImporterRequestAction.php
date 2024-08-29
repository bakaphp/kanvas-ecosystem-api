<?php
declare(strict_types=1);

namespace Kanvas\ImportersRequests\Actions;

use Kanvas\ImportersRequests\Models\ImporterRequest;
use Kanvas\ImportersRequests\DataTransferObject\ImporterRequest as ImporterRequestDto;

class CreateImporterRequestAction
{

    public function __construct(
        private ImporterRequestDto $dto
    ) {
    }

    public function executer(): ImporterRequest
    {
        return ImporterRequest::create([
            'apps_id' => $this->dto->app->getId(),
            'companies_id' => $this->dto->company->getId(),
            'companies_branches_id' => $this->dto->branch->getId(),
            'users_id' => $this->dto->user->getId(),
            'regions_id' => $this->dto->region->getId(),
            'filesystem_id' => $this->dto->filesystem->id,
            'status' => 0
        ]);
    }
}
