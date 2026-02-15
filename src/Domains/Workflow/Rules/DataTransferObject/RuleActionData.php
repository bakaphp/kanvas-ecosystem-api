<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\DataTransferObject;

use Kanvas\Workflow\Rules\Models\Action;
use Spatie\LaravelData\Data;

class RuleActionData extends Data
{
    public function __construct(
        public readonly Action $action,
        public readonly ?float $weight = null,
    ) {
    }
}
