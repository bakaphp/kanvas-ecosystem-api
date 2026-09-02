<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;

/**
 * @deprecated Use HasApprovals::requestApproval() on the entity directly.
 *
 * Kept as the one place that decides which engine owns an approval, so callers do not each carry the
 * branch. Hand it the entity and it opens a generic ApprovalRequest when the tenant has a policy;
 * without a policy — or without an entity — it writes the legacy accounting.approval_queue row exactly
 * as before.
 *
 * Never both. A legacy row the generic engine later resolved would sit `pending` forever, because only
 * ResolveApprovalAction knows how to close one.
 *
 * Delete this once every tenant has a policy seeded (kanvas:approvals:seed-scribe-policies).
 */
class RequestApprovalAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        protected readonly string $actionType,
        protected readonly string $targetType,
        protected readonly int $targetId,
        protected readonly ?UserInterface $requestedByUser = null,
        protected readonly array $payload = [],
        protected readonly ?Model $entity = null,
    ) {
    }

    /**
     * The generic request when this tenant is migrated, otherwise the legacy queue row.
     */
    public function execute(): ApprovalRequest|ApprovalQueueItem
    {
        $request = $this->entity !== null && method_exists($this->entity, 'requestApproval')
            ? $this->entity->requestApproval(
                $this->actionType,
                payload: $this->payload,
                requestedBy: $this->requestedByUser,
            )
            : null;

        return $request ?? $this->legacyQueueItem();
    }

    private function legacyQueueItem(): ApprovalQueueItem
    {
        return ApprovalQueueItem::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'requested_by_users_id' => $this->requestedByUser?->getId(),
            'action_type' => $this->actionType,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'payload' => $this->payload,
            'status' => ApprovalQueueStatusEnum::PENDING,
        ]);
    }
}
