<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

enum LeadGroupStatusEnum: string
{
    case WAITING = 'waiting';
    case CONTACTED = 'contacted';
}
