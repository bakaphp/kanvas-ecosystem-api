<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Deal;

use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Actions\CreateDealAction;
use Kanvas\Guild\Deals\Actions\UpdateDealAction;
use Kanvas\Guild\Deals\DataTransferObject\Deal as DealData;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Users\Models\Users;

class DealMutation
{
    public function create(mixed $rootValue, array $request): Deal
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);
        $input = $request['input'];

        return new CreateDealAction(
            $this->buildData($input, $app, $company, $user)
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Deal
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);
        $input = $request['input'];

        /** @var Deal $deal */
        $deal = Deal::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateDealAction(
            $deal,
            $this->buildData($input, $app, $company, $user, $deal)
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        /** @var Deal $deal */
        $deal = Deal::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return $deal->softDelete();
    }

    protected function buildData(
        array $input,
        Apps $app,
        CompanyInterface $company,
        UserInterface $user,
        ?Deal $existing = null
    ): DealData {
        $lead = null;
        if (isset($input['leads_id'])) {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp((int) $input['leads_id'], $company, $app);
        }

        $people = null;
        if (isset($input['people_id'])) {
            /** @var People $people */
            $people = People::getByIdFromCompanyApp((int) $input['people_id'], $company, $app);
        }

        $organization = null;
        if (isset($input['organization_id'])) {
            /** @var Organization $organization */
            $organization = Organization::getByIdFromCompanyApp((int) $input['organization_id'], $company, $app);
        }

        $owner = null;
        if (isset($input['owner_id'])) {
            /** @var Users $owner */
            $owner = Users::getById((int) $input['owner_id']);
        }

        $pipeline = null;
        if (isset($input['pipeline_id'])) {
            /** @var Pipeline $pipeline */
            $pipeline = Pipeline::getByIdFromCompanyApp((int) $input['pipeline_id'], $company, $app);
        }

        $pipelineStage = null;
        if (isset($input['pipeline_stage_id'])) {
            /** @var PipelineStage $pipelineStage */
            $pipelineStage = PipelineStage::getById((int) $input['pipeline_stage_id'], $app);
        }

        $branch = null;
        if (isset($input['companies_branches_id'])) {
            /** @var CompaniesBranches $branch */
            $branch = CompaniesBranches::getById((int) $input['companies_branches_id']);
        }

        return new DealData(
            app: $app,
            company: $company,
            user: $user,
            title: $input['title'] ?? $existing?->title ?? '',
            description: $input['description'] ?? $existing?->description,
            branch: $branch,
            lead: $lead,
            people: $people,
            organization: $organization,
            owner: $owner,
            pipeline: $pipeline,
            pipelineStage: $pipelineStage,
            statusId: isset($input['status_id']) ? (int) $input['status_id'] : null,
            status: isset($input['status']) ? (int) $input['status'] : null,
        );
    }
}
