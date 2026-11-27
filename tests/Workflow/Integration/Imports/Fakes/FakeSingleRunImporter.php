<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration\Imports\Fakes;

use Kanvas\Workflow\Integrations\Imports\Importers\SingleRunImporter;

final class FakeSingleRunImporter extends SingleRunImporter
{
    public function run(array $options): array
    {
        return ['ran' => true];
    }
}
