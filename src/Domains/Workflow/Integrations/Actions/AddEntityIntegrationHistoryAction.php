<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Integrations\DataTransferObject\EntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory as ModelsEntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\Status;

class AddEntityIntegrationHistoryAction
{
    public function __construct(
        protected EntityIntegrationHistory $dto,
        protected Apps $app,
        protected Status $status
    ) {
    }

    public function execute(): ModelsEntityIntegrationHistory
    {
        $integrationHistory = new ModelsEntityIntegrationHistory();
        $integrationHistory->entity_namespace = get_class($this->dto->entity);
        $integrationHistory->entity_id = $this->dto->entity->getId();
        $integrationHistory->apps_id = $this->app->getId();
        $integrationHistory->integrations_company_id = $this->dto->integrationCompany->getId();
        $integrationHistory->integrations_id = $this->dto->integrationCompany->integration->getId();
        $integrationHistory->status_id = $this->dto->status->getId();
        $integrationHistory->response = $this->dto->response;
        $integrationHistory->exception = $this->dto->exception;
        $integrationHistory->workflow_id = $this->dto->workflowId;

        $integrationHistory->saveOrFail();

        return $integrationHistory;
    }
}
