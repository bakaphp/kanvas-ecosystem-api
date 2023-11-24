<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Enums;

enum AbilityEnum: string
{
    case MANAGE_ROLES = 'MANAGE_ROLES';
    case MANAGE_USERS = 'MANAGE_USERS';
}
