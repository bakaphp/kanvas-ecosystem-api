<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\DataTransferObject;

use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;
use Spatie\LaravelData\Data;

class RuleConditionData extends Data
{
    public function __construct(
        public readonly string $attribute_name,
        public readonly RuleConditionOperatorEnum $operator,
        public readonly ?string $value = null,
    ) {
    }
}
