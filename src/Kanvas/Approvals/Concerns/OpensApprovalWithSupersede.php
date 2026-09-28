<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Concerns;

use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Models\ApprovalRequest;

/**
 * Shared by every workflow Activity that opens an approval_type on a HasApprovals entity: check for a
 * pending request of that type first, and either leave it blocking (default) or supersede it when the
 * Rule opted in via `auto_reject_stale_pending` (HasApprovals::supersedePendingApproval()). The caller
 * is responsible for confirming the entity actually uses HasApprovals before calling this.
 */
trait OpensApprovalWithSupersede
{
    protected function openOrSupersede(
        object $entity,
        string $approvalType,
        array $payload,
        array $params,
    ): array {
        $pending = $entity->pendingApproval($approvalType);

        if ($pending !== null) {
            if (! ($params['auto_reject_stale_pending'] ?? false)) {
                return ['requested' => false, 'reason' => 'already pending'];
            }

            $entity->supersedePendingApproval($approvalType);
        }

        /** @var ApprovalRequest|null $request */
        $request = $entity->requestApproval(
            $approvalType,
            payload: $payload,
            origin: ApprovalOriginEnum::SYSTEM,
        );

        return ['requested' => $request !== null, 'approval_request_id' => $request?->getId()];
    }
}
