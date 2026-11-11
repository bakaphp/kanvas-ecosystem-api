<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Transports\HttpHarnessTransport;
use Kanvas\Intelligence\AgentRuntime\Harness\Transports\SshExecHarnessTransport;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesAgentMachines;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\TestCase;

/**
 * How a request reaches the runtime is decided by the machine: its runtime first, then, for a
 * container, how the API can route to it.
 */
class HarnessFactoryTransportTest extends TestCase
{
    use CreatesAgentMachines;
    use CreatesTaskSessions;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testAnSshExecMachineUsesDockerExecOverSsh(): void
    {
        $session = $this->sessionOn($this->machine(network: 'ssh_exec'), ['container_name' => 'c']);

        $this->assertInstanceOf(SshExecHarnessTransport::class, HarnessFactory::transportFor($session));
    }

    public function testAnSshExecSessionWithoutAContainerIsRefused(): void
    {
        $session = $this->sessionOn($this->machine(network: 'ssh_exec'), ['container_name' => null]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no container');

        HarnessFactory::transportFor($session);
    }

    public function testAPrivateIpMachineUsesHttp(): void
    {
        $session = $this->sessionOn($this->machine(network: 'private_ip'), ['container_name' => 'c', 'port' => 20001]);

        $this->assertInstanceOf(HttpHarnessTransport::class, HarnessFactory::transportFor($session));
    }

    public function testAHostMachineUsesSshExec(): void
    {
        $session = $this->sessionOn(
            $this->machine(network: 'private_ip', runtime: 'host'),
            ['container_name' => 'com.kanvas.coding-agent-7', 'port' => 4101]
        );

        $this->assertInstanceOf(SshExecHarnessTransport::class, HarnessFactory::transportFor($session));
    }

    public function testAHostMachineWithoutAPortIsRefused(): void
    {
        $session = $this->sessionOn($this->machine(runtime: 'host'), ['container_name' => 'com.kanvas.coding-agent-7', 'port' => null]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no port');

        HarnessFactory::transportFor($session);
    }

    public function testAnUnprovisionedSessionIsRefused(): void
    {
        $session = $this->sessionOn($this->machine(), ['endpoint' => null]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('never provisioned');

        HarnessFactory::transportFor($session);
    }

    private function machine(string $network = 'ssh_exec', string $runtime = 'container'): AgentMachine
    {
        $machine = $this->createAgentMachine(runtime: $runtime);
        $machine->network_mode = $network;
        $machine->saveOrFail();

        return $machine;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function sessionOn(AgentMachine $machine, array $attributes): AgentTaskSession
    {
        return $this->createTaskSession(attributes: [
            'agent_machine_id' => $machine->getId(),
            'endpoint' => 'http://127.0.0.1:4096',
            'server_password' => 'pw',
            ...$attributes,
        ]);
    }
}
