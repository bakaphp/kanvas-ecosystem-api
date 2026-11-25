<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

enum AppUsersEmailModeEnum: string
{
    case PREVIEW = 'preview';
    case TEST = 'test';
    case SEND = 'send';
    case STATUS = 'status';
    case CANCEL = 'cancel';
}
