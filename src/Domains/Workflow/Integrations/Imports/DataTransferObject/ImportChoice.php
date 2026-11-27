<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Imports\DataTransferObject;

final readonly class ImportChoice
{
    public function __construct(public string $value, public string $label, public bool $suggested = false)
    {
    }
}
