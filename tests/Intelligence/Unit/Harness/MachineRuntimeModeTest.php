<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\TestCase;

/**
 * A machine row that predates the column, or a model built in memory, must read as a container
 * machine: that is what every machine was before the column existed.
 */
class MachineRuntimeModeTest extends TestCase
{
    public function testAMachineWithoutARuntimeValueIsAContainerMachine(): void
    {
        $this->assertSame(MachineRuntimeEnum::CONTAINER, new AgentMachine()->runtimeMode());
    }

    public function testAHostMachineReadsAsHost(): void
    {
        $machine = new AgentMachine();
        $machine->runtime = 'host';

        $this->assertSame(MachineRuntimeEnum::HOST, $machine->runtimeMode());
    }
}
