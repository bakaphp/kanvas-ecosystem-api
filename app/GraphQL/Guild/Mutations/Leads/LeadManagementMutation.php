<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadAttemptAction;
use Kanvas\Guild\Leads\Actions\UpdateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead;
use Kanvas\Guild\Leads\DataTransferObject\LeadUpdateInput;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;

class LeadManagementMutation
{
    use HasMutationUploadFiles;

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
            Lead::from($user, $app, $req['input']),
            $attempt
        );
        $lead = $createLead->execute();

        return $lead;
    }

    public function getLeadById(int $id, UserInterface $user, CompaniesBranches $branch, AppInterface $app): ModelsLead
    {
        if (! $user->isAppOwner()) {
            return ModelsLead::getByIdFromBranch($id, $branch, $app);
        }

        return ModelsLead::getById(
            id: $id,
            app: $app,
        );
    }

    public function update(mixed $root, array $req): ModelsLead
    {
        $user = auth()->user();
        $app = app(Apps::class);

        //@todo get from app
        $lead = $this->getLeadById(
            (int) $req['id'],
            $user,
            $user->getCurrentBranch(),
            $app
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
        $lead = $this->getLeadById(
            (int) $req['id'],
            $user,
            $user->getCurrentBranch(),
            app(Apps::class)
        );

        return $lead->softDelete();
    }

    public function restore(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $lead = ModelsLead::query()->where('id', (int) $req['id']);

        if (! $user->isAppOwner()) {
            $lead->where('companies_branches_id', $user->getCurrentBranch()->getId());
        } else {
            $lead->where('apps_id', $app->getId());
        }

        return $lead->firstOrFail()->restoreRecord();
    }

    public function attachFile(mixed $root, array $request): ModelsLead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        //$lead = ModelsLead::getByIdFromCompanyApp((int) $request['id'], $user->getCurrentCompany(), $app);
        $lead = $this->getLeadById(
            (int) $request['id'],
            $user,
            $user->getCurrentBranch(),
            $app
        );

        return $this->uploadFileToEntity(
            model: $lead,
            app: $app,
            user: $user,
            request: $request
        );
    }
}
