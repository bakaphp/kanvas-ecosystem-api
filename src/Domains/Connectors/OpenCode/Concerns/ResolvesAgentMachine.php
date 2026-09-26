<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Concerns;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Resolves the machine an agent is pinned to by its own custom field.
 *
 * Returns null when nothing is configured, so each caller decides what that means — provisioning falls
 * back to any active machine, killing a container has nothing to kill. An id that IS configured but
 * does not resolve always throws: silently falling through to "no machine" would read as "not set up
 * yet" for what is really a typo or a machine someone deleted.
 */
trait ResolvesAgentMachine
{
    private function configuredMachine(?Agent $agent): ?AgentMachine
    {
        $machineId = Str::trimToNull((string) $agent?->get(AgentCustomFieldEnum::MACHINE_ID->value));

        if ($agent === null || $machineId === null) {
            return null;
        }

        /** @var AgentMachine|null $machine */
        $machine = AgentMachine::query()
            ->where('id', (int) $machineId)
            ->fromApp($agent->app)
            ->fromCompany($agent->company)
            ->notDeleted()
            ->first();

        if ($machine === null) {
            // Tenant-scoped on purpose: an agent must not reach a machine belonging to another company
            // just because someone typed its id into a custom field.
            throw new ValidationException(
                'Machine ' . $machineId . ' is not available to this agent\'s company.'
            );
        }

        return $machine;
    }
}
