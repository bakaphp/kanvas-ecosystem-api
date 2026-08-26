<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

/** Shared authorization check for tools that resolve an item from the approval queue — used alongside HasKanvasContext. */
trait VerifiesApprovalAuthority
{
    protected function isAuthorizedApprover(string $approverEmail): bool
    {
        return $approverEmail !== '' && strcasecmp((string) $this->user->email, $approverEmail) === 0;
    }
}
