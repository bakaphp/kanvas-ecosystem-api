<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\OpenCode\Actions\ConfigureCodingMachineAction;
use Kanvas\Connectors\OpenCode\Enums\MachineCustomFieldEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesAgentMachines;
use Tests\TestCase;

/**
 * The machine half of `kanvas:coding:setup`, kept out of the command so it can be tested without
 * writing app settings to Redis.
 */
class ConfigureCodingMachineActionTest extends TestCase
{
    use CreatesAgentMachines;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testAHostRuntimeLeavesTheNetworkAlone(): void
    {
        $machine = $this->createAgentMachine();
        $machine->network_mode = 'shared_network';
        $machine->docker_network = 'net';
        $machine->saveOrFail();

        new ConfigureCodingMachineAction($machine, runtime: MachineRuntimeEnum::HOST)->execute();

        $machine->refresh();
        $this->assertSame('host', $machine->runtime);
        $this->assertSame('shared_network', $machine->network_mode);
        $this->assertSame('net', $machine->docker_network);
    }

    public function testAContainerWithANetworkIsSharedNetwork(): void
    {
        $machine = new ConfigureCodingMachineAction($this->createAgentMachine(), dockerNetwork: 'kanvas-net')->execute();

        $machine->refresh();
        $this->assertSame('container', $machine->runtime);
        $this->assertSame('shared_network', $machine->network_mode);
        $this->assertSame('kanvas-net', $machine->docker_network);
    }

    public function testAContainerWithoutANetworkIsSshExec(): void
    {
        $machine = $this->createAgentMachine();
        $machine->network_mode = 'shared_network';
        $machine->docker_network = 'net';
        $machine->saveOrFail();

        new ConfigureCodingMachineAction($machine, runtime: MachineRuntimeEnum::CONTAINER)->execute();

        $machine->refresh();
        $this->assertSame('ssh_exec', $machine->network_mode);
        $this->assertNull($machine->docker_network);
    }

    /**
     * `setup` is also how a key is rotated or a model changed; a run without `--runtime` must not
     * re-platform the box.
     */
    public function testNoRuntimeLeavesAHostMachineAHost(): void
    {
        $machine = $this->createAgentMachine();
        $machine->runtime = 'host';
        $machine->network_mode = 'shared_network';
        $machine->saveOrFail();

        new ConfigureCodingMachineAction($machine, dockerNetwork: 'kanvas-net')->execute();

        $machine->refresh();
        $this->assertSame('host', $machine->runtime);
        $this->assertSame('shared_network', $machine->network_mode);
    }

    public function testHostToolsAreStoredAsAList(): void
    {
        $machine = $this->createAgentMachine();

        new ConfigureCodingMachineAction($machine, runtime: MachineRuntimeEnum::HOST, hostTools: ['xcode'])->execute();
        $this->assertSame(['xcode'], $machine->get(MachineCustomFieldEnum::HOST_TOOLS->value));

        new ConfigureCodingMachineAction($machine, runtime: MachineRuntimeEnum::HOST)->execute();
        $this->assertSame(['xcode'], $machine->get(MachineCustomFieldEnum::HOST_TOOLS->value));
    }
}
