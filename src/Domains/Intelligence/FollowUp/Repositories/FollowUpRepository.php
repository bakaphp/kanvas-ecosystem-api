<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Repositories;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;

class FollowUpRepository
{
    public static function getFollowUpFromLead(Lead $lead, int $followUpType): ?FollowUp
    {
        // Example logic to retrieve a FollowUp based on Lead properties
        return FollowUp::where('pipelines_id', $lead->pipeline_id)
                       ->where('follow_up_type', $followUpType)
                       ->first();
    }
}
