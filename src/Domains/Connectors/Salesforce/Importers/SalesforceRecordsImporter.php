<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Importers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOutcomeEnum;
use Kanvas\Workflow\Integrations\Imports\Importers\ChunkedImporter;
use Override;

abstract class SalesforceRecordsImporter extends ChunkedImporter
{
    /**
     * @return list<array>
     */
    abstract protected function pullAll(): array;

    abstract protected function pull(string $salesforceId, array $record): Model;

    #[Override]
    public function list(array $options): array
    {
        return $this->pullAll();
    }

    #[Override]
    public function importOne(mixed $item, array $options): ImportOutcomeEnum
    {
        $salesforceId = $this->itemId($item);

        if ($salesforceId === '') {
            throw new ValidationException('Salesforce record is missing an Id');
        }

        return $this->pull($salesforceId, $item)->wasRecentlyCreated
            ? ImportOutcomeEnum::CREATED
            : ImportOutcomeEnum::UPDATED;
    }

    #[Override]
    public function itemId(mixed $item): string
    {
        return (string) ($item['Id'] ?? '');
    }
}
