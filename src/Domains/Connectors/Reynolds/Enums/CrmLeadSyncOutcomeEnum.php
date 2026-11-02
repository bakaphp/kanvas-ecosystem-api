<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Enums;

enum CrmLeadSyncOutcomeEnum: string
{
    case OPENED = 'opened';
    case ADOPTED = 'adopted';
    case CREATED_LEAD = 'created_lead';
    case CREATED_ALL = 'created_all';

    /** The CRM gave no lead id and there was no lead to open: the people is returned without a lead. */
    case PEOPLE_ONLY = 'people_only';
}
