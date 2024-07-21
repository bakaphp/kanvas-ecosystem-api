<?php

declare(strict_types=1);

namespace Kanvas\Enums;

enum ModuleEnum: int
{
    case ECOSYSTEM = 1;
    case INVENTORY = 2;
    case CRM = 3;
    case SOCIAL = 4;
    case WORKFLOW = 5;
    case ACTION_ENGINE = 6;
}
