<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Guild\Leads\Actions\AddLeadParticipantAction;
use Kanvas\Guild\Leads\Actions\RemoveLeadParticipantAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadsParticipant;

class Participants
{
    /**
     * Add participant to a lead.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return bool
     */
    public function add(mixed $root, array $req): bool
    {
        $leadParticipant = LeadsParticipant::viaRequest($req['input']);

        $action = new AddLeadParticipantAction($leadParticipant);

        return $action->execute() instanceof LeadsParticipant;
    }

    /**
     * Remove participant
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return bool
     */
    public function remove(mixed $root, array $req): bool
    {
        $leadParticipant = LeadsParticipant::viaRequest($req['input']);

        $action = new RemoveLeadParticipantAction($leadParticipant);

        return $action->execute();
    }
}
