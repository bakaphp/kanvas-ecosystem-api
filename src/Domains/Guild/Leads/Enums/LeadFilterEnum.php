<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

enum LeadFilterEnum: string
{
    case FITTER_BY_USER = 'FITTER_BY_USER';
    case FILTER_BY_BRANCH = 'FILTER_BY_BRANCH';
    case FILTER_BY_AGENTS = 'FILTER_BY_AGENTS';
    case FILTER_BY_SPONSOR = 'FILTER_BY_SPONSOR';
}
