<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * Thrown by HasApprovals::assertApproved() — the seatbelt for a call site that performs a gated side
 * effect without going through the policy's handler.
 */
class ApprovalRequiredException extends ValidationException
{
}
