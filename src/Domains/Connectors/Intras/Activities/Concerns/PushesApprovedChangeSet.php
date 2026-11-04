<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Activities\Concerns;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Connectors\Intras\Actions\PushToIntrasAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;

/**
 * Runs on ApprovalRequest::APPROVED — the permanent lane per src/Kanvas/Approvals/CLAUDE.md. Checks
 * the approval_type itself as well as relying on the Rule condition, because a Rule without the
 * condition would otherwise run on every approval of the company.
 */
trait PushesApprovedChangeSet
{
    /**
     * @return list<string>
     */
    abstract protected function approvalTypes(): array;

    /**
     * @return class-string<Model>
     */
    abstract protected function entityClass(): string;

    /**
     * @param ApprovalRequest $approvalRequest
     */
    public function execute(Model $approvalRequest, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! in_array($approvalRequest->approval_type, $this->approvalTypes(), true)) {
            return ['pushed' => false, 'reason' => 'not a ' . implode(' / ', $this->approvalTypes()) . ' approval'];
        }

        $entity = $approvalRequest->resolveEntity();
        $entityClass = $this->entityClass();

        if (! $entity instanceof $entityClass) {
            return ['pushed' => false, 'reason' => 'approval request does not resolve to a ' . class_basename($entityClass)];
        }

        $items = (array) ($approvalRequest->payload['items'] ?? []);

        if ($items === []) {
            return $this->failWorkflow(['pushed' => false, 'reason' => 'approval request carries no change set items']);
        }

        return $this->executeIntegration(
            entity: $approvalRequest,
            app: $app,
            integration: IntegrationsEnum::INTRAS,
            additionalParams: $params,
            integrationOperation: fn () => new PushToIntrasAction($entity, $items)->execute(),
            company: $entity->company,
        );
    }
}
