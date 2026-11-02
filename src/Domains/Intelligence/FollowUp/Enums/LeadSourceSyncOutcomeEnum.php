<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Enums;

enum LeadSourceSyncOutcomeEnum: string
{
    case NOT_APPLICABLE = 'not_applicable';
    case FRESH = 'fresh';
    case SYNCED = 'synced';
    case FAILED = 'failed';
}
