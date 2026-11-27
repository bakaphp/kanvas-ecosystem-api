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

    /**
     * Names a record in the error list. Fits items that are ids; override when items are records.
     */
    public function itemId(mixed $item): string
    {
        return (string) $item;
    }
}
