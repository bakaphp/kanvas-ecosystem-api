<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Connectors\OpenCode\Runtime\CodingRuntimeDriverFactory;
use Kanvas\Connectors\OpenCode\Runtime\ContainerRuntimeDriver;
use Kanvas\Connectors\OpenCode\Runtime\HostProcessRuntimeDriver;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\TestCase;

class CodingRuntimeDriverFactoryTest extends TestCase
{
    public function testAContainerMachineGetsTheContainerDriver(): void
    {
        $this->assertInstanceOf(ContainerRuntimeDriver::class, CodingRuntimeDriverFactory::forMachine(new AgentMachine()));
    }

    public function testAHostMachineGetsTheHostProcessDriver(): void
    {
        $machine = new AgentMachine();
        $machine->runtime = 'host';

        $this->assertInstanceOf(HostProcessRuntimeDriver::class, CodingRuntimeDriverFactory::forMachine($machine));
    }
}
