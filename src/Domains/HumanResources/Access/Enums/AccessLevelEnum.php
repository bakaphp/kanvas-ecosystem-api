<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Access\Enums;

enum AccessLevelEnum: string
{
    case NONE = 'none';
    case VIEW = 'view';
    case MANAGE = 'manage';
}
