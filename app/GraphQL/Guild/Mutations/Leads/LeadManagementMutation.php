<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use App\GraphQL\Concerns\FiresAfterUploadWorkflow;
use Baka\Contracts\AppInterface;
use Baka\Support\IPInfo;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadAttemptAction;
use Kanvas\Guild\Leads\Actions\UpdateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead;
use Kanvas\Guild\Leads\DataTransferObject\LeadUpdateInput;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Guild\Leads\Models\LeadStatus;

class LeadManagementMutation
{
    use FiresAfterUploadWorkflow;

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
            IPInfo::getClientIp(),
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
            IPInfo::getClientIp(),
            'API - Update'
        );
        $attempt = $leadAttempt->execute();

        if (empty($req['input']['title'])) {
            $req['input']['title'] = $lead->title ?? ($lead->people?->name ? $lead->people->name . ' Opp' : 'No title');
        }

        if (empty($req['input']['branch_id'])) {
            $req['input']['branch_id'] = $lead->companies_branches_id;
        }

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

        $lead->deleteAllCustomFields();

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
        $lead = $this->getLeadById(
            (int) $request['id'],
            $user,
            $user->getCurrentBranch(),
            $app
        );

        //@todo this is a hack , to remove , once frontend move the logic to upload directly to the people
        $target = $lead;
        if (isset($request['params']['people_id']) && $request['params']['people_id'] != $lead->people_id) {
            $target = People::getByIdFromCompanyApp(
                id: (int) $request['params']['people_id'],
                app: $app,
                company: $user->getCurrentCompany()
            );
        }

        $this->attachFilesThenFireAfterUpload(
            $lead,
            $target,
            $app,
            $user,
            $request
        );

        return $lead;
    }

    public function wonOrLost(mixed $root, array $req): ModelsLead
    {
        $leadStatus = LeadStatus::getByName($req['status']);
        $lead = ModelsLead::getByIdFromCompanyApp(
            id: (int) $req['id'],
            company: auth()->user()->getCurrentCompany(),
            app: app(Apps::class),
        );
        $lead->leads_status_id = $leadStatus->getId();
        $lead->reason_lost = $req['reason_lost'] ?? '';
        $lead->save();

        return $lead;
    }
}
