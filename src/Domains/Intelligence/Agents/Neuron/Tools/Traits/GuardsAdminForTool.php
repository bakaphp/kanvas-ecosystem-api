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
}
