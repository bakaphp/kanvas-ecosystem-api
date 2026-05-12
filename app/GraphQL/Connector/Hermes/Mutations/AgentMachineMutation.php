<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Hermes\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Hermes\Jobs\UpdateHermesOnMachineJob;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Hermes-specific AgentMachine mutations.
 *
 * Only exposes `updateContainers` — AgentMachine CRUD itself is provider-agnostic and
 * lives on the OpenClaw resolver. If a third provider lands or if AgentMachine CRUD
 * needs decoupling from OpenClaw, lift to a generic resolver.
 */
class AgentMachineMutation
{
    public function updateContainers(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentMachine $machine */
        $machine = AgentMachine::getByIdFromCompanyApp((int) $request['machine_id'], $company, $app);

        UpdateHermesOnMachineJob::dispatch($machine);

        return true;
    }
}
