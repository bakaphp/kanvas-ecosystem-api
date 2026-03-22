<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\OpenClaw\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\CreateAgentMachineAction;
use Kanvas\Intelligence\Agents\Actions\UpdateAgentMachineAction;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentMachine as AgentMachineData;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

class AgentMachineMutation
{
    public function create(mixed $root, array $request): AgentMachine
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        return new CreateAgentMachineAction(
            new AgentMachineData(
                app: $app,
                company: $company,
                name: $input['name'],
                host: $input['host'],
                ssh_user: $input['ssh_user'],
                ssh_private_key: $input['ssh_private_key'],
                ssh_port: $input['ssh_port'] ?? 22,
                region: $input['region'] ?? null,
                port_range_start: $input['port_range_start'] ?? 20000,
                port_range_end: $input['port_range_end'] ?? 30000,
                max_agents: $input['max_agents'] ?? 100,
            ),
        )->execute();
    }

    public function update(mixed $root, array $request): AgentMachine
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $input = $request['input'];

        /** @var AgentMachine $machine */
        $machine = AgentMachine::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateAgentMachineAction(
            $machine,
            new AgentMachineData(
                app: $app,
                company: $company,
                name: $input['name'] ?? $machine->name,
                host: $input['host'] ?? $machine->host,
                ssh_user: $input['ssh_user'] ?? $machine->ssh_user,
                ssh_private_key: $input['ssh_private_key'] ?? $machine->ssh_private_key,
                ssh_port: $input['ssh_port'] ?? $machine->ssh_port,
                region: $input['region'] ?? $machine->region,
                port_range_start: $input['port_range_start'] ?? $machine->port_range_start,
                port_range_end: $input['port_range_end'] ?? $machine->port_range_end,
                max_agents: $input['max_agents'] ?? $machine->max_agents,
                is_active: $input['is_active'] ?? $machine->is_active,
            ),
        )->execute();
    }

    public function delete(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentMachine $machine */
        $machine = AgentMachine::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        if ($machine->activeDeployments()->count() > 0) {
            throw new ValidationException('Cannot delete machine with active deployments. Terminate all deployments first.');
        }

        return $machine->softDelete();
    }
}
