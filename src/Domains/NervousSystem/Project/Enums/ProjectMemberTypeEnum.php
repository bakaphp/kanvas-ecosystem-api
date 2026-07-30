<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Enums;

enum ProjectMemberTypeEnum: string
{
    case USER = 'user';
    case AGENT = 'agent';
}
