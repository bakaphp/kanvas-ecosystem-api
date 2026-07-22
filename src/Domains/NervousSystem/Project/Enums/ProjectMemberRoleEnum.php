<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Enums;

enum ProjectMemberRoleEnum: string
{
    case OWNER = 'owner';
    case MANAGER = 'manager';
    case CONTRIBUTOR = 'contributor';
    case REVIEWER = 'reviewer';
    case VIEWER = 'viewer';
}
