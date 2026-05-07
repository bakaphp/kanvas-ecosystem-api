<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Queries\Leads;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelFilesService;

class LeadChannelFilesQuery
{
    public function getChannelFiles(Lead $lead, array $args): array
    {
        $action = new LeadChannelFilesService($lead);

        return $action->getChannelFiles([
            'includeParticipants' => $args['includeParticipants'] ?? true,
            'groupByAction' => $args['groupByAction'] ?? true,
        ]);
    }
}
