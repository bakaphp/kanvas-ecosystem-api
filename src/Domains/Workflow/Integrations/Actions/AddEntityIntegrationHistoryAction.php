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
        $integrationCompany = $this->dto->integrationCompany;

        $integrationHistory = new ModelsEntityIntegrationHistory();
        $integrationHistory->entity_namespace = get_class($this->dto->entity);
        $integrationHistory->entity_id = $this->dto->entity->getId();
        $integrationHistory->apps_id = $this->app->getId();
        $integrationHistory->integrations_company_id = $integrationCompany?->getId();
        $integrationHistory->companies_id = $integrationCompany?->company->getId() ?? $this->dto->company?->getId();
        $integrationHistory->integrations_id = $integrationCompany?->integration->getId() ?? $this->dto->integration?->getId();
        $integrationHistory->status_id = $this->dto->status->getId();
        $integrationHistory->response = $this->dto->response;
        $integrationHistory->exception = $this->dto->exception;
        $integrationHistory->workflow_id = $this->dto->workflowId;
        $integrationHistory->rules_id = $this->dto->rule?->getId();

        $integrationHistory->saveOrFail();

        return $integrationHistory;
    }
}
