<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Imports\DataTransferObject;

final readonly class Importable
{
    /**
     * @param class-string $importer
     * @param list<ImportOption> $options
     */
    public function __construct(
        public string $entity,
        public string $label,
        public string $importer,
        public array $options = [],
    ) {
    }
}
