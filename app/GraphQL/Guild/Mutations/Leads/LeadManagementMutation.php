<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadAttemptAction;
use Kanvas\Guild\Leads\Actions\RemoveLeadParticipantAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead;
use Kanvas\Guild\Leads\DataTransferObject\LeadsParticipant;

class LeadManagementMutation
{
    /**
     * Add participant to a lead.
     */
    public function create(mixed $root, array $req)
    {
        $leadAttempt = new CreateLeadAttemptAction(
            $req,
            request()->headers->all(),
            auth()->user()->getCurrentCompany(),
            request()->ip(),
            'API'
        );
        $leadAttempt->execute();

        $createLead = new CreateLeadAction(
            Lead::viaRequest($req['input'])
        );
        $lead = $createLead->execute();


        print_r($lead); die();
    }

    /**
     * Remove participant
     */
    public function update(mixed $root, array $req): bool
    {
        $leadParticipant = LeadsParticipant::viaRequest($req['input']);

        $action = new RemoveLeadParticipantAction($leadParticipant);

        return $action->execute();
    }

    /**
     * Remove participant
     */
    public function delete(mixed $root, array $req): bool
    {
        $leadParticipant = LeadsParticipant::viaRequest($req['input']);

        $action = new RemoveLeadParticipantAction($leadParticipant);

        return $action->execute();
    }
}
