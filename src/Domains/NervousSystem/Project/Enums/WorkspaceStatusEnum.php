<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Enums;

enum WorkspaceStatusEnum: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}
