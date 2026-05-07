<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\Enums;

enum TaskStatusEnum: int
{
    case ASSIGNED = 0;
    case STARTED = 1;
    case SUCCESSFUL = 2;
    case FAILED = 3;
    case IN_PROGRESS = 4;
    case UNASSIGNED = 6;
    case ACCEPTED = 7;
    case DECLINED = 8;
    case CANCEL = 9;
    case DELETED = 10;
}
