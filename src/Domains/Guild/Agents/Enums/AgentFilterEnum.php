<?php

declare(strict_types=1);

namespace Kanvas\Guild\Agents\Enums;

enum AgentFilterEnum: string
{
    case FITTER_BY_USER = 'FITTER_BY_USER';
    case FITTER_BY_OWNER = 'FITTER_BY_OWNER';
    case FILTER_BY_BRANCH = 'FILTER_BY_BRANCH';
    case MEMBER_NUMBER = 'member_number_';
}
