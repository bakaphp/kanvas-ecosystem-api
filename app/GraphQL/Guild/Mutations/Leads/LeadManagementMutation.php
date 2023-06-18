<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Leads\Actions\AddLeadParticipantAction;
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
        $leadData = Lead::viaRequest($req['input']);

        print_r($leadData);
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
