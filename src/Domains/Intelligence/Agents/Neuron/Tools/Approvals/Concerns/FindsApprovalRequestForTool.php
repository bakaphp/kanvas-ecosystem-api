<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Approvals\Concerns;

use Kanvas\Approvals\Models\ApprovalRequest;

/**
 * The newest approval request on a record, scoped to the tool's tenant. Requires HasKanvasContext.
 */
trait FindsApprovalRequestForTool
{
    protected function latestApprovalRequest(string $targetType, int $targetId): ?ApprovalRequest
    {
        /** @var ApprovalRequest|null $request */
        $request = ApprovalRequest::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('approval_type', ApprovalRequest::approvalTypeFor($targetType))
            ->where('entity_id', $targetId)
            ->where('is_deleted', false)
            ->latest('id')
            ->first();

        return $request;
    }
}
