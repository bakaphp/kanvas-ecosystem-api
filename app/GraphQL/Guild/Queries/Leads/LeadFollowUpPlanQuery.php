<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Queries\Leads;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpPlanService;

class LeadFollowUpPlanQuery
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(Lead $lead): ?array
    {
        return new LeadFollowUpPlanService()->status($lead);
    }
}
