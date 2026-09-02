<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;

/**
 * @deprecated Use HasApprovals::requestApproval() on the entity directly.
 *
 * Kept as the one place that decides which engine owns an approval, so callers do not each carry the
 * branch. Hand it an entity using HasApprovals: it opens a generic ApprovalRequest when the tenant has
 * a policy, and writes the legacy accounting.approval_queue row when it has none.
 *
 * app, company and target id are read off the entity rather than passed beside it — a caller handing
 * in a company that disagreed with the record's own is a tenant-mismatch waiting to happen.
 *
 * Never both. A legacy row the generic engine later resolved would sit `pending` forever, because only
 * ResolveApprovalAction knows how to close one.
 *
 * Delete this once every tenant has a policy seeded (kanvas:approvals:seed-scribe-policies).
 */
class RequestApprovalAction
{
    public function __construct(
        protected readonly Model $entity,
        protected readonly string $targetType,
        protected readonly ?UserInterface $requestedByUser = null,
        protected readonly array $payload = [],
    ) {
    }

    /**
     * The generic request when this tenant is migrated, otherwise the legacy queue row.
     */
    public function execute(): ApprovalRequest|ApprovalQueueItem
    {
        // Null means the tenant has no policy — the one legitimate reason to fall back. An entity
        // without HasApprovals fails here instead, which is right: that is a wiring mistake, and
        // quietly writing a legacy row would hide it behind an approval that still looks fine.
        $request = $this->entity->requestApproval(
            ApprovalRequest::approvalTypeFor($this->targetType),
            payload: $this->payload,
            requestedBy: $this->requestedByUser,
        );

        return $request ?? $this->legacyQueueItem();
    }

    private function legacyQueueItem(): ApprovalQueueItem
    {
        return ApprovalQueueItem::create([
            'apps_id' => $this->entity->app->getId(),
            'companies_id' => $this->entity->company->getId(),
            'requested_by_users_id' => $this->requestedByUser?->getId(),
            'action_type' => ApprovalRequest::approvalTypeFor($this->targetType),
            'target_type' => $this->targetType,
            'target_id' => $this->entity->getKey(),
            'payload' => $this->payload,
            'status' => ApprovalQueueStatusEnum::PENDING,
        ]);
    }
}
