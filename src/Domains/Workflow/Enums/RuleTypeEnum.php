<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Enums;

enum RuleTypeEnum: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
}
