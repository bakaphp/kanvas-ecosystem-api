<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Contracts;

use Kanvas\Approvals\Models\ApprovalRequest;

/**
 * For a handler whose entity is left in a limbo when nobody answers: a request that expired or was
 * withdrawn is not a rejection — no one decided — but the thing waiting on it still has to be told
 * it will never hear a decision. The request's status says which of the two it was.
 */
interface ApprovalWithdrawalHandlerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function withdrawn(ApprovalRequest $request, ?string $reason): array;
}
