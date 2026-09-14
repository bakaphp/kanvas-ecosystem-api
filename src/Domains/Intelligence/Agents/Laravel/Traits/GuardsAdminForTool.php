<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Traits;

use Kanvas\Users\Models\Users;

/**
 * Laravel-AI counterpart of the Neuron GuardsAdminForTool. Laravel tools return text rather than a
 * structured payload, so the denial is a sentence for the model instead of an error array.
 *
 * Host needs HasKanvasContext for contextUser().
 */
trait GuardsAdminForTool
{
    protected function adminDenialFor(string $action): ?string
    {
        $user = $this->contextUser();

        if ($user instanceof Users && $user->isAdmin()) {
            return null;
        }

        return "Only an administrator can {$action}.";
    }
}
