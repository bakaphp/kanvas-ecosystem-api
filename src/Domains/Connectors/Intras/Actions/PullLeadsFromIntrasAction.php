<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\LeadMapper;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;

class PullLeadsFromIntrasAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
        protected ?string $lastSyncAt = null,
        protected ?int $agencyId = null
    ) {
    }

    public function execute(): int
    {
        $client = new Client($this->app);

        $query = $client->table('quotes')
            ->where('is_deleted', 0);

        if ($this->agencyId !== null) {
            $query->where('agencies_id', $this->agencyId);
        }

        if ($this->lastSyncAt !== null) {
            $query->where('updated_at', '>=', $this->lastSyncAt);
        }

        $pipeline = Pipeline::where('apps_id', $this->app->getId())
            ->fromCompany($this->company)
            ->where('is_default', 1)
            ->first();

        $defaultStatus = LeadStatus::where('name', 'Active')->first();
        $branch = $this->company->defaultBranch;
        $count = 0;

        $query->orderBy('id')->chunk(500, function ($rows) use (&$count, $pipeline, $defaultStatus, $branch) {
            foreach ($rows as $row) {
                $existing = Lead::fromApp($this->app)
                    ->fromCompany($this->company)
                    ->whereHas(
                        'customFields',
                        fn (Builder $q) => $q->where('name', CustomFieldEnum::INTRAS_QUOTE_ID->value)->where('value', $row->id)
                    )
                    ->first();

                if ($existing) {
                    $count++;

                    continue;
                }

                $mapped = LeadMapper::fromIntras($row);
                $stageSlug = LeadMapper::quoteStatusToStage($row->quotes_statuses_id);

                $stage = $pipeline ? PipelineStage::where('pipelines_id', $pipeline->getId())
                    ->where('name', $stageSlug)
                    ->first() : null;

                $people = $this->findPeopleByIntrasParticipantId($row->participants_id);
                $org = $this->findOrganizationByIntrasCompanyId($row->companies_id);

                $lead = new Lead();
                $lead->apps_id = $this->app->getId();
                $lead->companies_id = $this->company->getId();
                $lead->companies_branches_id = $branch?->getId() ?? 0;
                $lead->users_id = $this->user->getId();
                $lead->leads_owner_id = $this->user->getId();
                $lead->people_id = $people?->getId() ?? 0;
                $lead->organization_id = $org?->getId();
                $lead->title = $mapped['title'];
                $lead->pipeline_id = $pipeline?->getId() ?? 0;
                $lead->pipeline_stage_id = $stage?->getId() ?? 0;
                $lead->leads_status_id = $defaultStatus?->getId() ?? 0;
                $lead->description = $row->info_objectives ?? null;
                $lead->disableWorkflows();
                if ($row->created_at !== null) {
                    $lead->created_at = $row->created_at;
                }
                if ($row->updated_at !== null) {
                    $lead->updated_at = $row->updated_at;
                }
                $lead->saveOrFail();

                $lead->set(CustomFieldEnum::INTRAS_QUOTE_ID->value, $row->id);

                foreach ($mapped['custom_fields'] as $key => $value) {
                    if ($value !== null) {
                        $lead->set($key, $value);
                    }
                }

                $count++;
            }
        });

        return $count;
    }

    protected function findPeopleByIntrasParticipantId(?int $intrasId): ?People
    {
        if ($intrasId === null) {
            return null;
        }

        return People::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereHas(
                'customFields',
                fn (Builder $q) => $q->where('name', CustomFieldEnum::INTRAS_PARTICIPANT_ID->value)->where('value', $intrasId)
            )
            ->first();
    }

    protected function findOrganizationByIntrasCompanyId(?int $intrasId): ?Organization
    {
        if ($intrasId === null) {
            return null;
        }

        return Organization::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereHas(
                'customFields',
                fn (Builder $q) => $q->where('name', CustomFieldEnum::INTRAS_COMPANY_ID->value)->where('value', $intrasId)
            )
            ->first();
    }
}
