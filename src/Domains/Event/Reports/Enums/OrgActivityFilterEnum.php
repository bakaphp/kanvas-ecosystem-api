<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\Enums;

enum OrgActivityFilterEnum: string
{
    case ALL = 'all';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case LAPSED = 'lapsed';
    case NEW = 'new';
}
