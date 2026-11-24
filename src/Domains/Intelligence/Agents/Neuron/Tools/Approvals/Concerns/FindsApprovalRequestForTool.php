<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Approvals\Concerns;

use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;

/**
 * The newest approval request on a record, scoped to the tool's tenant, on the generic approvals domain
 * and on the legacy accounting queue it falls back to. Requires HasKanvasContext.
 *
 * Matched on approval_type rather than a target_type -> model-class map, so adding a new approvable type
 * stays a policy row and never touches a tool.
 */
trait FindsApprovalRequestForTool
{
    protected function latestApprovalRequest(
        string $targetType,
        int $targetId,
        ?ApprovalStatusEnum $status = null
    ): ?ApprovalRequest {
        /** @var ApprovalRequest|null $request */
        $request = ApprovalRequest::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('approval_type', ApprovalRequest::approvalTypeFor($targetType))
            ->where('entity_id', $targetId)
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->where('is_deleted', false)
            ->latest('id')
            ->first();

        return $request;
    }

    protected function latestLegacyApprovalItem(
        string $targetType,
        int $targetId,
        ?ApprovalQueueStatusEnum $status = null
    ): ?ApprovalQueueItem {
        /** @var ApprovalQueueItem|null $item */
        $item = ApprovalQueueItem::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->latest('id')
            ->first();

        return $item;
    }
}
