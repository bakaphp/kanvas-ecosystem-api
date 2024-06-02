<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadAttemptAction;
use Kanvas\Guild\Leads\Actions\UpdateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead;
use Kanvas\Guild\Leads\DataTransferObject\LeadUpdateInput;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;

class LeadManagementMutation
{
    /**
     * Create new lead
     */
    public function create(mixed $root, array $req): ModelsLead
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $leadAttempt = new CreateLeadAttemptAction(
            $req,
            request()->headers->all(),
            $user->getCurrentCompany(),
            $app,
            request()->ip(),
            'API - Create'
        );
        $attempt = $leadAttempt->execute();

        $createLead = new CreateLeadAction(
            Lead::viaRequest($user, $req['input']),
            $attempt,
            $app
        );
        $lead = $createLead->execute();

        return $lead;
    }

    public function update(mixed $root, array $req): ModelsLead
    {
        $user = auth()->user();
        $app = app(Apps::class);

        //@todo get from app
        $lead = ModelsLead::getByIdFromBranch(
            $req['id'],
            $user->getCurrentBranch()
        );

        $leadAttempt = new CreateLeadAttemptAction(
            $req,
            request()->headers->all(),
            $user->getCurrentCompany(),
            $app,
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

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $lead = ModelsLead::getByIdFromBranch(
            $req['id'],
            $user->getCurrentBranch()
        );

        return $lead->softDelete();
    }

    public function restore(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $lead = ModelsLead::where('id', $req['id'])
                            ->where('companies_branches_id', $user->getCurrentBranch()->getId())
                            ->firstOrFail();

        return $lead->restoreRecord();
    }
}
