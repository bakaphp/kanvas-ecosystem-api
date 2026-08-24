<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Scribe\Approvals\Enums\ApprovalConfigurationEnum;

/** Shared authorization check for tools that resolve an item from the approval queue — used alongside HasKanvasContext. */
trait VerifiesApprovalAuthority
{
    protected function isAuthorizedApprover(): bool
    {
        $approverEmail = (string) ($this->app->get(ApprovalConfigurationEnum::APPROVER_EMAIL->value) ?? '');

        return $approverEmail !== '' && strcasecmp((string) $this->user->email, $approverEmail) === 0;
    }
}
