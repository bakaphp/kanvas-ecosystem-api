<?php

declare(strict_types=1);

namespace Kanvas\Guild\Deals\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Deals\DataTransferObject\Deal as DealData;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Workflow\Enums\WorkflowEnum;

class UpdateDealAction
{
    public function __construct(
        protected readonly Deal $deal,
        protected readonly DealData $data,
        protected readonly bool $runWorkflow = true,
    ) {
    }

    public function execute(): Deal
    {
        return DB::connection('crm')->transaction(function () {
            $this->deal->title = $this->data->title;
            $this->deal->description = $this->data->description;

            if ($this->data->branch !== null) {
                $this->deal->companies_branches_id = $this->data->branch->getId();
            }
            if ($this->data->lead !== null) {
                $this->deal->leads_id = $this->data->lead->getId();
            }
            if ($this->data->people !== null) {
                $this->deal->people_id = $this->data->people->getId();
            }
            if ($this->data->organization !== null) {
                $this->deal->organization_id = $this->data->organization->getId();
            }
            if ($this->data->owner !== null) {
                $this->deal->owner_id = $this->data->owner->getId();
            }
            if ($this->data->pipeline !== null) {
                $this->deal->pipeline_id = $this->data->pipeline->getId();
            }
            if ($this->data->pipelineStage !== null) {
                $this->deal->pipeline_stage_id = $this->data->pipelineStage->getId();
            }
            if ($this->data->statusId !== null) {
                $this->deal->status_id = $this->data->statusId;
            }
            if ($this->data->status !== null) {
                $this->deal->status = $this->data->status;
            }

            $this->deal->saveOrFail();

            if ($this->runWorkflow) {
                $this->deal->fireWorkflow(
                    WorkflowEnum::UPDATED->value,
                    true
                );
            }

            return $this->deal;
        });
    }
}
