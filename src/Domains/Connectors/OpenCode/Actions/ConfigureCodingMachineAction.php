<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Enums\MachineCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Services\CodingHostToolsService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineNetworkModeEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * The machine half of `kanvas:coding:setup`: which runtime the box runs and how the API reaches it.
 *
 * The only writer of `agent_machines.runtime`. A host machine is reached by SSH whatever its
 * `network_mode` says, so that column is left alone for one; a container machine keeps today's rule —
 * a Docker network means "host it next to us" (`shared_network`), none means `ssh_exec`.
 */
class ConfigureCodingMachineAction
{
    /**
     * @param list<string>|null $hostTools null leaves the field as it is; an empty list clears it
     */
    public function __construct(
        private readonly AgentMachine $machine,
        private readonly ?MachineRuntimeEnum $runtime = null,
        private readonly ?string $dockerNetwork = null,
        private readonly ?array $hostTools = null,
    ) {
    }

    /**
     * No runtime given means "keep the one it has": setup is also how a key is rotated or a model
     * changed, and a re-run must never re-platform a live host.
     */
    public function execute(): AgentMachine
    {
        $hostTools = $this->hostTools === null ? null : CodingHostToolsService::normalize($this->hostTools);
        $unknown = CodingHostToolsService::unknown($hostTools ?? []);

        // Refused rather than dropped: `forMachine()` ignores a tool it does not know, so a typo would
        // save, print back, and advertise nothing.
        if ($unknown !== []) {
            throw new ValidationException(
                'Unknown host tools ' . implode(', ', $unknown) . '; known: ' . implode(', ', CodingHostToolsService::KNOWN) . '.'
            );
        }

        $runtime = $this->runtime ?? $this->machine->runtimeMode();
        $this->machine->runtime = $runtime->value;

        if ($runtime === MachineRuntimeEnum::CONTAINER) {
            $network = Str::trimToNull($this->dockerNetwork);
            $this->machine->network_mode = $network !== null
                ? MachineNetworkModeEnum::SHARED_NETWORK->value
                : MachineNetworkModeEnum::SSH_EXEC->value;
            $this->machine->docker_network = $network;
        }

        $this->machine->saveOrFail();

        if ($hostTools !== null) {
            $this->machine->set(MachineCustomFieldEnum::HOST_TOOLS->value, $hostTools);
        }

        return $this->machine;
    }
}
