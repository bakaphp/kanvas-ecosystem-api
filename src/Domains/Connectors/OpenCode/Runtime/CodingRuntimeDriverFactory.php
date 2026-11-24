<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Runtime;

use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\CodingRuntimeDriver;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

final class CodingRuntimeDriverFactory
{
    public static function forMachine(AgentMachine $machine): CodingRuntimeDriver
    {
        return match ($machine->runtimeMode()) {
            MachineRuntimeEnum::CONTAINER => new ContainerRuntimeDriver($machine),
            MachineRuntimeEnum::HOST => new HostProcessRuntimeDriver($machine),
        };
    }
}
