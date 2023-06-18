<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadAttemptAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;

class LeadManagementMutation
{
    /**
     * Create new lead
     */
    public function create(mixed $root, array $req): ModelsLead
    {
        $leadAttempt = new CreateLeadAttemptAction(
            $req,
            request()->headers->all(),
            auth()->user()->getCurrentCompany(),
            request()->ip(),
            'API'
        );
        $attempt = $leadAttempt->execute();

        $createLead = new CreateLeadAction(
            Lead::viaRequest($req['input'])
        );
        $lead = $createLead->execute();

        $attempt->leads_id = $lead->getId();
        $attempt->saveOrFail();

        return $lead;
    }

    public function update(mixed $root, array $req)
    {
    }

    public function delete(mixed $root, array $req)
    {
    }
}
