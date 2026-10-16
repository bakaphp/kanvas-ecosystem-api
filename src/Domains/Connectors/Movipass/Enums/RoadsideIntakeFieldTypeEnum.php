<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum RoadsideIntakeFieldTypeEnum: string
{
    case TEXT = 'text';
    case BOOLEAN = 'boolean';
    case NUMBER = 'number';
    case CHOICE = 'choice';
    case DATETIME = 'datetime';
}
