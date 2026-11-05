<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Actions\KillAgentCodingContainerAction;
use Kanvas\Connectors\OpenCode\Actions\StopCodingSessionRuntimeAction;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\SshClient as CodingSshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\CodingRuntimeDriver;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Mockery;
use RuntimeException;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesAgentMachines;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\Intelligence\Integration\Harness\Fakes\FakeCodingSshClient;
use Tests\TestCase;

/**
 * The runtime belongs to the agent and several tasks share it, so only the last session out removes
 * it — and "shares it" means on the same machine: the handle is derived from the agent id, so two
 * machines hosting the same agent carry the same name for two different runtimes.
 */
class StopCodingSessionRuntimeTest extends TestCase
{
    use CreatesAgentMachines;
    use CreatesTaskSessions;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private const string HANDLE = 'kanvas-coding-agent-7300';
    private const int AGENT = 7300;

    public function testAnotherLiveSessionOnTheSameMachineKeepsTheRuntime(): void
    {
        $machine = $this->createAgentMachine();
        $session = $this->sessionOn($machine);
        $this->sessionOn($machine);
        $ssh = $this->ssh();

        $this->assertFalse($this->stop($session, $ssh)->execute());
        $this->assertSame([], $ssh->commands);
    }

    public function testAnotherMachinesSessionWithTheSameHandleDoesNotBlockRemoval(): void
    {
        $session = $this->sessionOn($this->createAgentMachine());
        $this->sessionOn($this->createAgentMachine());
        $ssh = $this->ssh();

        $this->assertTrue($this->stop($session, $ssh)->execute());
        $this->assertSame([self::HANDLE], $ssh->removedContainers());
    }

    public function testTheLastSessionOutRemovesTheRuntime(): void
    {
        $session = $this->sessionOn($this->createAgentMachine());
        $ssh = $this->ssh();

        $this->assertTrue($this->stop($session, $ssh)->execute());
        $this->assertSame([self::HANDLE], $ssh->removedContainers());
    }

    public function testAnAttachedSessionHasNothingToRemove(): void
    {
        $session = $this->sessionOn($this->createAgentMachine(), handle: null);
        $ssh = $this->ssh();

        $this->assertFalse($this->stop($session, $ssh)->execute());
        $this->assertSame([], $ssh->commands);
    }

    public function testAnUnreachableMachineDoesNotPreventSessionCleanup(): void
    {
        $session = $this->sessionOn($this->createAgentMachine());
        $action = new class ($session) extends StopCodingSessionRuntimeAction {
            protected function openSshClient(AgentMachine $machine): SshClient
            {
                throw new RuntimeException('SSH login failed');
            }
        };

        $this->assertFalse($action->execute());
    }

    public function testFailedKillLeavesRunningSessionsOpen(): void
    {
        $machine = $this->createAgentMachine();
        $session = $this->sessionOn($machine);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('could not be removed. Its sessions remain open.');

        try {
            $this->kill($machine, removed: false)->execute('Runtime is unresponsive');
        } finally {
            $this->assertSame(HarnessStatusEnum::RUNNING->value, $session->refresh()->status);
        }
    }

    public function testSuccessfulKillClosesOnlySessionsOnThatMachine(): void
    {
        $machine = $this->createAgentMachine();
        $session = $this->sessionOn($machine);
        $elsewhere = $this->sessionOn($this->createAgentMachine());

        $result = $this->kill($machine, removed: true)->execute('Runtime is unresponsive');

        $this->assertTrue($result['removed']);
        $this->assertSame(1, $result['sessions_closed']);
        $this->assertContains($session->refresh()->status, HarnessStatusEnum::terminalValues());
        $this->assertSame(HarnessStatusEnum::RUNNING->value, $elsewhere->refresh()->status);
    }

    private function kill(AgentMachine $machine, bool $removed): KillAgentCodingContainerAction
    {
        $agent = Mockery::mock(Agent::class)->makePartial();
        $agent->id = self::AGENT;
        $agent->setRelation('app', app(Apps::class));
        $agent->setRelation('company', auth()->user()->getCurrentCompany());
        $agent->shouldReceive('get')->with(AgentCustomFieldEnum::MACHINE_ID->value)->andReturn($machine->getId());
        $ssh = $this->ssh();
        $driver = Mockery::mock(CodingRuntimeDriver::class);
        $driver->shouldReceive('handleFor')->once()->with($agent)->andReturn(self::HANDLE);
        $driver->shouldReceive('remove')->once()->with(self::HANDLE, $ssh)->andReturn($removed);

        return new class ($agent, $driver, $ssh) extends KillAgentCodingContainerAction {
            public function __construct(Agent $agent, private readonly CodingRuntimeDriver $runtimeDriver, private readonly FakeCodingSshClient $ssh)
            {
                parent::__construct($agent);
            }

            protected function driver(AgentMachine $machine): CodingRuntimeDriver
            {
                return $this->runtimeDriver;
            }

            protected function openSshClient(AgentMachine $machine): CodingSshClient
            {
                return $this->ssh;
            }
        };
    }

    private function sessionOn(AgentMachine $machine, ?string $handle = self::HANDLE): AgentTaskSession
    {
        return $this->createTaskSession(HarnessStatusEnum::RUNNING, [
            'agent_id' => self::AGENT,
            'agent_machine_id' => $machine->getId(),
            'container_name' => $handle,
        ]);
    }

    private function ssh(): FakeCodingSshClient
    {
        return new FakeCodingSshClient(['docker ps -aq' => '']);
    }

    private function stop(AgentTaskSession $session, FakeCodingSshClient $ssh): StopCodingSessionRuntimeAction
    {
        return new class ($session, $ssh) extends StopCodingSessionRuntimeAction {
            public function __construct(AgentTaskSession $session, private readonly FakeCodingSshClient $ssh)
            {
                parent::__construct($session);
            }

            protected function openSshClient(AgentMachine $machine): SshClient
            {
                return $this->ssh;
            }
        };
    }
}
