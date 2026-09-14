<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Traits;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Messages\Actions\CreateMessageFromTypeAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Contracts\SilentWorkflowException;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\AddEntityIntegrationHistoryAction;
use Kanvas\Workflow\Integrations\DataTransferObject\EntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory as ModelsEntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Rules\Models\Rule;
use Throwable;

trait ActivityIntegrationTrait
{
    protected ?StatusEnum $workflowStatus = null;
    protected ?ModelsEntityIntegrationHistory $lastIntegrationHistory = null;

    public function setWorkflowStatus(StatusEnum $status): void
    {
        $this->workflowStatus = $status;
    }

    public function failWorkflow(array $message): array
    {
        $this->setWorkflowStatus(StatusEnum::FAILED);

        return $message;
    }

    public function getStatus(StatusEnum $status): ?Status
    {
        return Status::where('slug', $status->value)
        ->where('apps_id', 0)
        ->first();
    }

    public function getIntegrationCompany(
        IntegrationsEnum $integration,
        Regions $region,
        Status $status,
        ?Companies $company = null
    ): ?IntegrationsCompany {
        // A global region (companies_id = 0) has no owning company, so it can only
        // ever be a fallback — the integration belongs to the tenant, not the region.
        $company ??= $region->company;

        if (! $company instanceof Companies) {
            return null;
        }

        return IntegrationsCompany::getByIntegration(
            company: $company,
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
        mixed $historyResponse = null,
        ?Throwable $exception = null,
        ?Rule $rule = null,
        mixed $input = null
    ): ModelsEntityIntegrationHistory {
        $dto = new EntityIntegrationHistory(
            app: $app,
            integrationCompany: $integrationCompany,
            status: $status,
            entity: $entity,
            input: $input,
            response: $historyResponse ?? null,
            exception: $exception,
            workflowId: $this->workflowId(),
            rule: $rule
        );

        $this->lastIntegrationHistory = new AddEntityIntegrationHistoryAction(
            dto: $dto,
            app: $app,
            status: $status
        )->execute();

        return $this->lastIntegrationHistory;
    }

    protected function buildIntegrationHistoryInput(Model $entity, array $params): array
    {
        return [
            'entity' => $entity->toArray(),
            'params' => $params,
        ];
    }

    public function executeIntegration(
        Model $entity,
        AppInterface $app,
        IntegrationsEnum $integration,
        callable $integrationOperation,
        array $additionalParams = [],
        ?Regions $region = null,
        ?Companies $company = null,
        bool $throwException = false
    ): array {
        $this->overwriteAppService($app);
        $activeStatus = $this->getStatus(StatusEnum::ACTIVE);
        $company = $company ?? $entity->company;

        if (! $company instanceof Companies) {
            return [
                'error' => 'No company configured for this entity',
                'integration' => $integration->value,
                'entity_id' => $entity->getId(),
            ];
        }

        $region = $region ?? Regions::getDefault($company, $app);

        if (! $region) {
            return [
                'error' => 'No region configured for this company',
                'integration' => $integration->value,
                'company' => $company->getId(),
                'entity_id' => $entity->getId(),
            ];
        }

        $integrationCompany = $this->getIntegrationCompany(
            $integration,
            $region,
            $activeStatus,
            $company
        );

        if (! $integrationCompany) {
            return [
                'error' => 'No integration configured for this company',
                'region' => $region->getId(),
                'integration' => $integration->value,
                'company' => $company->getId(),
                'entity_id' => $entity->getId(),
            ];
        }

        $response = null;
        $exception = null;
        $status = $activeStatus;

        try {
            // Execute the integration operation
            $response = $integrationOperation($entity, $app, $integrationCompany, $additionalParams);
            $status = $this->getStatus($this->workflowStatus ?? StatusEnum::CONNECTED);
        } catch (Throwable $exception) {
            $status = $this->getStatus(StatusEnum::FAILED);

            if (! $exception instanceof SilentWorkflowException) {
                report($exception);
            }
        }

        // Record integration history
        $this->addToIntegrationHistory(
            $app,
            $integrationCompany,
            $status,
            $entity,
            $response ?? null,
            $exception,
            rule: $additionalParams['rule'] ?? null,
            input: $this->buildIntegrationHistoryInput($entity, $additionalParams)
        );

        if ($throwException && $exception) {
            throw $exception;
        }

        if ($exception) {
            return [
                'error' => $exception->getMessage(),
                'company' => $company->getId(),
                'integration' => $integration->value,
                'entity_id' => $entity->getId(),
                'trace' => $exception->getTraceAsString(),
            ];
        }

        return $response;
    }

    public function createIntegrationMessageByVerb(
        mixed $response,
        string $messageTypeVerb,
        string $messageTypeName,
        AppInterface $app,
        Model $entity
    ): ?Message {
        try {
            $messageType = MessagesTypesRepository::getGlobalByVerbAndName($messageTypeVerb, $messageTypeName, $app);
            $systemModule = SystemModules::fromPublicApp()->where('model_name', get_class($entity))->firstOrFail();
        } catch (ModelNotFoundException|ExceptionsModelNotFoundException $e) {
            report($e);

            return null;
        }

        $createMessageAction = new CreateMessageFromTypeAction(
            user: $entity->user,
            company: $entity->company,
            app: $app
        );

        return $createMessageAction->execute(
            messageType: $messageType,
            data: json_encode($response),
            systemModule: $systemModule,
            entityId: $entity->getId()
        );
    }
}
