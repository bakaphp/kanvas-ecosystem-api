<?php

declare(strict_types=1);

namespace Kanvas\Imports\Actions;

use Kanvas\Imports\Actions\Concerns\ValidatesImportSource;
use Kanvas\Imports\DataTransferObject\ImportSourceData;
use Kanvas\Imports\Models\ImportSource;

class UpdateImportSourceAction
{
    use ValidatesImportSource;

    public function __construct(
        private readonly ImportSource $source,
        private readonly ImportSourceData $data,
    ) {
    }

    public function execute(): ImportSource
    {
        $this->source->update($this->validatedAttributes($this->data));

        return $this->source;
    }
}
