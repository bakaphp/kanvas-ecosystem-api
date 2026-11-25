<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Imports\Importers;

abstract class SingleRunImporter extends IntegrationImporter
{
    /**
     * @return array the run's summary
     */
    abstract public function run(array $options): array;
}
