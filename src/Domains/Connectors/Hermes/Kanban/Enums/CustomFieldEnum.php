<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Enums;

enum CustomFieldEnum: string
{
    // On Agent: the Hermes profile name used as the kanban `assignee`.
    case HERMES_KANBAN_PROFILE = 'HERMES_KANBAN_PROFILE';
}
