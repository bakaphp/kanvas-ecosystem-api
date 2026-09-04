<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Contracts;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Models\ApprovalRequest;

/**
 * The mirror of ApprovalHandlerInterface for the "no" branch — discard the draft, release the hold,
 * put the record back where it came from.
 *
 * Separate from ApprovalHandlerInterface rather than a second method on it: most approvals have
 * nothing to undo (a bill that is not approved simply is not pushed), so forcing every handler to
 * declare an empty reject() would be noise. A handler implements this only when rejecting has a side
 * effect of its own.
 */
interface ApprovalRejectionHandlerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function reject(ApprovalRequest $request, ?UserInterface $approver, ?string $reason): array;
}
