<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

/** Shared authorization check for tools that resolve an item from the approval queue — used alongside HasKanvasContext. */
trait VerifiesApprovalAuthority
{
    /**
     * @param list<string> $approverEmails
     */
    protected function isAuthorizedApprover(array $approverEmails): bool
    {
        foreach ($approverEmails as $approverEmail) {
            if ($approverEmail !== '' && strcasecmp((string) $this->user->email, $approverEmail) === 0) {
                return true;
            }
        }

        return false;
    }
}
