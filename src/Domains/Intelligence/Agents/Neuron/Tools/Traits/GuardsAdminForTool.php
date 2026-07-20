<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Users\Models\Users;

/**
 * Gate a mutating tool on an admin — the tool-layer mirror of the GraphQL `@guardByAdmin` directive.
 *
 * Authorization is checked against the REQUESTING (human) user — the person the agent is helping —
 * NOT the agent's own acting user. An agent must not let a non-admin human drive privileged writes
 * through it just because the agent's own user happens to be an admin. Set it with
 * forRequestingUser($agentHumanUser); when unset it falls back to the tool's context user.
 *
 *   if ($denied = $this->requireAdminOrError()) { return $denied; }
 */
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
}
