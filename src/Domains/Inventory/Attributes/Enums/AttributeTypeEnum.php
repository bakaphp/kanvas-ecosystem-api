<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Enums;

enum AttributeTypeEnum: string
{
    case INPUT = 'input';
    case CHECKBOX = 'checkbox';
    case JSON = 'json';
}
