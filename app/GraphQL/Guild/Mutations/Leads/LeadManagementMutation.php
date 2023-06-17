<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Guild\Leads\Actions\AddLeadParticipantAction;
use Kanvas\Guild\Leads\Actions\RemoveLeadParticipantAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadsParticipant;

class LeadManagementMutation
{
    /**
     * Add participant to a lead.
     */
    public function create(mixed $root, array $req): bool
    {
        $leadParticipant = LeadsParticipant::viaRequest($req['input']);

        $action = new AddLeadParticipantAction($leadParticipant);

        return $action->execute() instanceof LeadsParticipant;
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
