<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadAttemptAction;
use Kanvas\Guild\Leads\Actions\UpdateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead;
use Kanvas\Guild\Leads\DataTransferObject\LeadUpdateInput;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;

class LeadManagementMutation
{
    /**
     * Create new lead
     */
    public function create(mixed $root, array $req): ModelsLead
    {
        $user = auth()->user();
        $leadAttempt = new CreateLeadAttemptAction(
            $req,
            request()->headers->all(),
            $user->getCurrentCompany(),
            request()->ip(),
            'API'
        );
        $attempt = $leadAttempt->execute();

        $createLead = new CreateLeadAction(
            Lead::viaRequest($user,$req['input'])
        );
        $lead = $createLead->execute();

        return $lead;
    }

    public function update(mixed $root, array $req): ModelsLead
    {
        $user = auth()->user();
      
        $lead = ModelsLead::getByIdFromBranch(
            $req['id'],
            $user->getCurrentBranch()
        );

        $leadAttempt = new CreateLeadAttemptAction(
            $req,
            request()->headers->all(),
            $user->getCurrentCompany(),
            request()->ip(),
            'API - Update'
        );
        $attempt = $leadAttempt->execute();

        $leadInputData = LeadUpdateInput::from($req['input']);
        $updateLeadAction = new UpdateLeadAction(
            $lead,
            $leadInputData,
            $user,
            $attempt
        );

        return $updateLeadAction->execute();
    }

    public function delete(mixed $root, array $req)
    {
    }
}
