<?php

declare(strict_types=1);

namespace Kanvas\Guild\Deals\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Models\Deal as DealModel;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class Deal extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?CompaniesBranches $branch = null,
        public readonly ?Lead $lead = null,
        public readonly ?People $people = null,
        public readonly ?Organization $organization = null,
        public readonly ?Users $owner = null,
        public readonly ?Pipeline $pipeline = null,
        public readonly ?PipelineStage $pipelineStage = null,
        public readonly ?int $statusId = null,
        public readonly ?int $status = null,
    ) {
    }

    public static function fromMultiple(
        UserInterface $user,
        AppInterface $app,
        CompanyInterface $company,
        array $request,
        ?DealModel $existing = null
    ): self {
        $branch = isset($request['companies_branches_id'])
            ? CompaniesBranches::getById((int) $request['companies_branches_id'])
            : null;

        $lead = null;
        if (isset($request['leads_id'])) {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp((int) $request['leads_id'], $company, $app);
        }

        if ($branch === null) {
            $branch = $lead?->branch ?? $user->getCurrentCompany()->branch;
        }

        $people = null;
        if (isset($request['people_id'])) {
            /** @var People $people */
            $people = People::getByIdFromCompanyApp((int) $request['people_id'], $company, $app);
        }

        $organization = null;
        if (isset($request['organization_id'])) {
            /** @var Organization $organization */
            $organization = Organization::getByIdFromCompanyApp((int) $request['organization_id'], $company, $app);
        }

        $owner = null;
        if (isset($request['owner_id'])) {
            /** @var Users $owner */
            $owner = Users::getById((int) $request['owner_id']);
        }

        $pipeline = null;
        if (isset($request['pipeline_id'])) {
            /** @var Pipeline $pipeline */
            $pipeline = Pipeline::getByIdFromCompanyApp((int) $request['pipeline_id'], $company, $app);
        }

        $pipelineStage = null;
        if (isset($request['pipeline_stage_id'])) {
            /** @var PipelineStage $pipelineStage */
            $pipelineStage = PipelineStage::getById((int) $request['pipeline_stage_id'], $app);
        }

        return new self(
            app: $app,
            company: $company,
            user: $user,
            title: (string) ($request['title'] ?? $existing?->title ?? ''),
            description: $request['description'] ?? $existing?->description,
            branch: $branch,
            lead: $lead,
            people: $people,
            organization: $organization,
            owner: $owner,
            pipeline: $pipeline,
            pipelineStage: $pipelineStage,
            statusId: isset($request['status_id']) ? (int) $request['status_id'] : null,
            status: isset($request['status']) ? (int) $request['status'] : null,
        );
    }
}
