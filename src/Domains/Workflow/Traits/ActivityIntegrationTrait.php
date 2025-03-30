<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Traits;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Contracts\EntityIntegrationInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\AddEntityIntegrationHistoryAction;
use Kanvas\Workflow\Integrations\DataTransferObject\EntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Throwable;

trait ActivityIntegrationTrait
{
    public function getStatus(StatusEnum $status): ?Status
    {
        return Status::where('slug', $status->value)
        ->where('apps_id', 0)
        ->first();
    }

    public function getIntegrationCompany(
        IntegrationsEnum $integration,
        Regions $region,
        Status $status
    ): ?IntegrationsCompany {
        return IntegrationsCompany::getByIntegration(
            company: $region->company,
            status: $status,
            region: $region,
            name: $integration->value
        );
    }

    public function addToIntegrationHistory(
        AppInterface $app,
        IntegrationsCompany $integrationCompany,
        Status $status,
        Model $entity,
        ?string $historyResponse = null,
        ?Throwable $exception = null
    ): void {
        $dto = new EntityIntegrationHistory(
            app: $app,
            integrationCompany: $integrationCompany,
            status: $status,
            entity: $entity,
            response: $historyResponse ?? null,
            exception: $exception,
            workflowId: $this->workflowId()
        );

        (new AddEntityIntegrationHistoryAction(
            dto: $dto,
            app: $app,
            status: $status
        ))->execute();
    }

    public function executeIntegration(
        Model $entity,
        AppInterface $app,
        IntegrationsEnum $integration,
        callable $integrationOperation,
        array $additionalParams = [],
        ?Regions $region = null,
        ?Companies $company = null
    ): array {
        $this->overwriteAppService($app);
        $activeStatus = $this->getStatus(StatusEnum::ACTIVE);
        $region = $region ?? Regions::getDefault($company ?? $entity->company, $app);

        $integrationCompany = $this->getIntegrationCompany(
            $integration,
            $region,
            $activeStatus
        );

        if (! $integrationCompany) {
            return [
                'error' => 'No integration configured for this company',
                'integration' => $integration->value,
                'company' => $entity->company->getId(),
                'entity_id' => $entity->getId(),
            ];
        }

        $response = null;
        $exception = null;
        $status = $activeStatus;

        try {
            // Execute the integration operation
            $response = $integrationOperation($entity, $app, $integrationCompany, $additionalParams);
            $status = $this->getStatus(StatusEnum::CONNECTED);
        } catch (Throwable $exception) {
            $status = $this->getStatus(StatusEnum::FAILED);
        }

        // Record integration history
        $this->addToIntegrationHistory(
            $app,
            $integrationCompany,
            $status,
            $entity,
            $response ?? null,
            $exception
        );

        if ($exception) {
            return [
                'error' => $exception->getMessage(),
                'company' => $entity->company->getId(),
                'integration' => $integration->value,
                'entity_id' => $entity->getId(),
            ];
        }

        return $response;
    }
}
