<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Imports\Importers;

use Kanvas\Workflow\Integrations\Imports\Enums\ImportOutcomeEnum;

abstract class ChunkedImporter extends IntegrationImporter
{
    /**
     * What to import. Items are cached between chunks, so they must be serialisable.
     *
     * @return list<mixed>
     */
    abstract public function list(array $options): array;

    abstract public function importOne(mixed $item, array $options): ImportOutcomeEnum;

    abstract public function itemId(mixed $item): string;
}
