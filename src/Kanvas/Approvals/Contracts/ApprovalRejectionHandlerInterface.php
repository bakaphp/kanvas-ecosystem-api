<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Contracts;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Models\ApprovalRequest;

/**
 * ApprovalHandlerInterface's "no" branch, implemented only when rejecting has a side effect of its
 * own. Separate rather than a second method on it because most approvals have nothing to undo — a
 * bill that is not approved is simply not pushed.
 */
interface ApprovalRejectionHandlerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function reject(ApprovalRequest $request, ?UserInterface $approver, ?string $reason): array;
}
