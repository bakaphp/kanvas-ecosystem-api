<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Contracts;

use Kanvas\Approvals\Models\ApprovalRequest;

/**
 * Implemented by an approvable entity left in limbo when its request expires — a bill parked in
 * PENDING_APPROVAL that nobody can act on once nobody is asked anymore. What expiry means is the
 * entity's business, so the generic sweep calls this instead of branching on entity type.
 *
 * Distinct from ApprovalWithdrawalHandlerInterface, which belongs to the policy's handler (often a
 * connector) and so differs per tenant; the entity's own state machine does not.
 */
interface ReactsToApprovalExpiryInterface
{
    public function approvalExpired(ApprovalRequest $request): void;
}
