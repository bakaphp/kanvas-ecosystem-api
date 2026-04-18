<?php

declare(strict_types=1);

namespace Kanvas\Guild\Deals\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Deals\DataTransferObject\Deal as DealData;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Workflow\Enums\WorkflowEnum;

class CreateDealAction
{
    public function __construct(
        protected readonly DealData $data,
        protected readonly bool $runWorkflow = true,
    ) {
    }

    public function execute(): Deal
    {
        return DB::connection('crm')->transaction(function () {
            $deal = new Deal();
            $deal->apps_id = $this->data->app->getId();
            $deal->companies_id = $this->data->company->getId();
            $deal->companies_branches_id = $this->data->branch?->getId()
                ?? $this->data->lead?->companies_branches_id
                ?? 0;
            $deal->users_id = $this->data->user->getId();
            $deal->owner_id = $this->data->owner?->getId() ?? $this->data->user->getId();
            $deal->leads_id = $this->data->lead?->getId();
            $deal->people_id = $this->data->people?->getId() ?? $this->data->lead?->people_id ?? 0;
            $deal->organization_id = $this->data->organization?->getId() ?? 0;
            $deal->pipeline_id = $this->data->pipeline?->getId() ?? 0;
            $deal->pipeline_stage_id = $this->data->pipelineStage?->getId() ?? 0;
            $deal->status_id = $this->data->statusId ?? 1;
            $deal->status = $this->data->status ?? 0;
            $deal->title = $this->data->title;
            $deal->description = $this->data->description;
            $deal->saveOrFail();

            if ($this->runWorkflow) {
                $deal->fireWorkflow(
                    WorkflowEnum::CREATED->value,
                    true
                );
            }

            return $deal;
        });
    }
}
