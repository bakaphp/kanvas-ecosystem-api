<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Concerns;

use Kanvas\Approvals\Models\ApprovalRequest;

/**
 * ReactsToApprovalExpiryInterface for a Scribe document: an expired request leaves the document in draft
 * with a note saying why, instead of stranding it waiting on a request nobody can answer anymore. A
 * document with its own pending state (a bill's PENDING_APPROVAL) overrides returnToDraftAfterExpiry().
 */
trait ReturnsToDraftOnApprovalExpiry
{
    public function approvalExpired(ApprovalRequest $request): void
    {
        $note = "Approval request #{$request->getId()} expired with no decision; back in draft. "
            . 'Submit it for approval again when it is ready.';

        $this->returnToDraftAfterExpiry($note);

        $this->refresh();
        $this->appendInternalNote($note);
        $this->saveOrFail();
    }

    protected function returnToDraftAfterExpiry(string $reason): void
    {
    }
}
