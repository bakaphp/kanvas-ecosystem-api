<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Users\Models\Users;

trait GuardsAdminForTool
{
    protected ?Users $requestingUser = null;

    public function forRequestingUser(?Users $user): static
    {
        $this->requestingUser = $user;

        return $this;
    }

    /**
     * @return array{created: false, updated: false, message: string}|null Error payload when denied, null when allowed.
     */
    protected function requireAdminOrError(): ?array
    {
        $actor = $this->requestingUser ?? ($this->user ?? null);

        if ($actor instanceof Users && $actor->isAdmin()) {
            return null;
        }

        // Both flags so create-style and update-style tools each read a falsey outcome.
        return [
            'created' => false,
            'updated' => false,
            'message' => 'Only an administrator can perform this action.',
        ];
    }

    /**
     * Same guard, but with no fallback to the tool's context user. An agent's own user is usually an
     * admin, so on the registry path (where the context user IS the agent) `requireAdminOrError()`
     * would authorize anyone talking to the agent. Tools whose blast radius is the whole company —
     * automation that then runs unattended — must know the human and deny when they don't.
     *
     * @return array{created: false, updated: false, message: string}|null Error payload when denied, null when allowed.
     */
    protected function requireRequestingAdminOrError(): ?array
    {
        if ($this->requestingUser instanceof Users && $this->requestingUser->isAdmin()) {
            return null;
        }

        return [
            'created' => false,
            'updated' => false,
            'message' => $this->requestingUser instanceof Users
                ? 'Only a company administrator can perform this action.'
                : 'This action needs to be requested by a known company administrator, and this conversation '
                    . 'has no identified user. Ask the person to run it from their own Kanvas account.',
        ];
    }
}
