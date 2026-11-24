<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineNetworkModeEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;
use Kanvas\Intelligence\Agents\Models\AgentMachine as AgentMachineModel;
use Spatie\LaravelData\Data;

class AgentMachine extends Data
{
    /**
     * The last four are null for "leave the column as it is". The GraphQL mutations never send them,
     * and an update that defaulted `runtime` to `container` would silently demote a host machine.
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $name,
        public readonly string $host,
        public readonly string $ssh_user,
        public readonly string $ssh_private_key,
        public readonly int $ssh_port = 22,
        public readonly ?string $region = null,
        public readonly int $port_range_start = 20000,
        public readonly int $port_range_end = 30000,
        public readonly int $max_agents = 100,
        public readonly bool $is_active = true,
        public readonly ?MachineRuntimeEnum $runtime = null,
        public readonly ?MachineNetworkModeEnum $network_mode = null,
        public readonly ?string $docker_network = null,
        public readonly ?string $private_host = null,
    ) {
    }

    public function applyTo(AgentMachineModel $machine): void
    {
        $machine->name = $this->name;
        $machine->host = $this->host;
        $machine->ssh_port = $this->ssh_port;
        $machine->ssh_user = $this->ssh_user;
        $machine->ssh_private_key = $this->ssh_private_key;
        $machine->region = $this->region;
        $machine->port_range_start = $this->port_range_start;
        $machine->port_range_end = $this->port_range_end;
        $machine->max_agents = $this->max_agents;
        $machine->is_active = $this->is_active;

        if ($this->runtime !== null) {
            $machine->runtime = $this->runtime->value;
        }

        if ($this->network_mode !== null) {
            $machine->network_mode = $this->network_mode->value;
        }

        if ($this->docker_network !== null) {
            $machine->docker_network = $this->docker_network;
        }

        if ($this->private_host !== null) {
            $machine->private_host = $this->private_host;
        }
    }
}
