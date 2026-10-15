<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

enum CrmLeadSyncOutcomeEnum: string
{
    case OPENED = 'opened';
    case ADOPTED = 'adopted';
    case CREATED_LEAD = 'created_lead';
    case CREATED_ALL = 'created_all';
}
