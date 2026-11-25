<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Imports\DataTransferObject;

use Kanvas\Workflow\Integrations\Imports\Enums\ImportOptionTypeEnum;

final readonly class ImportOption
{
    public function __construct(
        public string $name,
        public ImportOptionTypeEnum $type,
        public string $label,
        public bool $required = false,
        public bool $multiple = false,
    ) {
    }
}
