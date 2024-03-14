<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Guild\Leads\Models\LeadStatus;

class LeadStatusManagementMutation
{
    public function create(mixed $root, array $request): LeadStatus
    {
        $leadStatus = LeadStatus::create($request['input']);

        return $leadStatus;
    }

    public function update(mixed $root, array $request): LeadStatus
    {
        $leadStatus = LeadStatus::findOrFail($request['id']);
        $leadStatus->update($request['input']);

        return $leadStatus;
    }

    public function delete(mixed $root, array $request): bool
    {
        $leadStatus = LeadStatus::findOrFail($request['id']);

        return $leadStatus->delete();
    }
}
