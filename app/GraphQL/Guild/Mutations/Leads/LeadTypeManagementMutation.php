<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Guild\Leads\Actions\CreateLeadTypeAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadType;

class LeadTypeManagementMutation
{
    /**
     * Create a new lead type.
     */
    public function create(mixed $root, array $req): bool
    {
        $leadType = LeadType::from($req['input']);

        return (new CreateLeadTypeAction($leadType))->create();
    }
}
