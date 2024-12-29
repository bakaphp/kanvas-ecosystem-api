<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Actions;

use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement;
use Kanvas\ActionEngine\Engagements\Models\Engagement as ModelsEngagement;

class CreateEngagementAction
{
    public function __construct(
        protected readonly Engagement $engagement
    ) {
    }

    public function execute(): ModelsEngagement
    {
        return ModelsEngagement::firstOrCreate([
            'companies_actions_id' => $this->engagement->companyAction->getId(),
            'pipelines_stages_id' => $this->engagement->pipelineStage->getId(),
            'entity_uuid' => $this->engagement->entityUuid,
            'apps_id' => $this->engagement->companyAction->app->getId(),
            'companies_id' => $this->engagement->companyAction->company->getId(),
            'is_deleted' => 0,
         ], [
            'slug' => $this->engagement->slug,
            'message_id' => $this->engagement->message->getId(),
            'lead_id' => $this->engagement->lead->getId(),
         ]);
    }
}
