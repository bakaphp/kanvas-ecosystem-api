<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration\Imports\Fakes;

use Baka\Contracts\BaseIntegration;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\Importable;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\ImportOption;
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOptionTypeEnum;

final class FakeImportHandler extends BaseIntegration
{
    public function setup(): bool
    {
        return true;
    }

    public function importables(): array
    {
        return [
            new Importable(
                entity: 'chunky',
                label: 'Chunky',
                importer: FakeChunkedImporter::class,
                options: [
                    new ImportOption(
                        name: 'region_ids',
                        type: ImportOptionTypeEnum::CHOICES,
                        label: 'Regions',
                        required: true,
                        multiple: true,
                    ),
                    new ImportOption(
                        name: 'flag',
                        type: ImportOptionTypeEnum::BOOLEAN,
                        label: 'Flag',
                    ),
                ],
            ),
            new Importable(entity: 'plain', label: 'Plain', importer: FakeChunkedImporter::class),
            new Importable(entity: 'whole', label: 'Whole', importer: FakeSingleRunImporter::class),
        ];
    }
}
