<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Guild\Leads\Actions\AddLeadParticipantAction;
use Kanvas\Guild\Leads\Actions\RemoveLeadParticipantAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadsParticipant;
use Kanvas\Guild\Leads\Models\LeadParticipant;

class ParticipantMutation
{
    /**
     * Add participant to a lead.
     */
    public function add(mixed $root, array $req): bool
    {
        $leadParticipant = LeadsParticipant::viaRequest($req['input']);

        $action = new AddLeadParticipantAction($leadParticipant);

        return $action->execute() instanceof LeadParticipant;
    }

    /**
     * Remove participant
     */
    public function remove(mixed $root, array $req): bool
    {
        $leadParticipant = LeadsParticipant::viaRequest($req['input']);

        $action = new RemoveLeadParticipantAction($leadParticipant);

        return $action->execute();
    }
}
