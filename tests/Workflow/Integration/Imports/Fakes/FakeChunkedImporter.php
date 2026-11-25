<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration\Imports\Fakes;

use Kanvas\Workflow\Integrations\Imports\Enums\ImportOutcomeEnum;
use Kanvas\Workflow\Integrations\Imports\Importers\ChunkedImporter;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use RuntimeException;

final class FakeChunkedImporter extends ChunkedImporter
{
    public function prepare(array $options): array
    {
        return $options === [] ? $options : $options + ['prepared' => true];
    }

    public function list(array $options): array
    {
        return FakeImportRegistry::$items;
    }

    public function importOne(mixed $item, array $options): ImportOutcomeEnum
    {
        if (in_array($item, FakeImportRegistry::$failOn, true)) {
            throw new RuntimeException("cannot import {$item}");
        }

        FakeImportRegistry::$imported[] = $item;

        return ImportOutcomeEnum::CREATED;
    }

    public function itemId(mixed $item): string
    {
        return (string) $item;
    }

    public function completed(IntegrationImport $run): ?array
    {
        return FakeImportRegistry::$completed;
    }
}
