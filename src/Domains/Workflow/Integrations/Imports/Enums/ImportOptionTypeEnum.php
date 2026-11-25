<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Imports\Enums;

enum ImportOptionTypeEnum: string
{
    case CHOICES = 'choices';
    case BOOLEAN = 'boolean';
    case STRING = 'string';
}
