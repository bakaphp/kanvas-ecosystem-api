<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class WorkflowAction
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $description = null,
    ) {
    }
}
