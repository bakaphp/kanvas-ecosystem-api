<?php

declare(strict_types=1);

namespace Kanvas\Imports\Actions;

use Kanvas\Imports\Actions\Concerns\ValidatesImportSource;
use Kanvas\Imports\DataTransferObject\ImportSourceData;
use Kanvas\Imports\Models\ImportSource;

class CreateImportSourceAction
{
    use ValidatesImportSource;

    public function __construct(
        private readonly ImportSourceData $data,
    ) {
    }

    public function execute(): ImportSource
    {
        return ImportSource::create(array_merge(
            [
                'apps_id' => $this->data->app->getId(),
                'companies_id' => $this->data->branch->company->getId(),
                'companies_branches_id' => $this->data->branch->getId(),
            ],
            $this->validatedAttributes($this->data)
        ));
    }
}
