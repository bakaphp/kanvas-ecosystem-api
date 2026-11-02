<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\OpenCode\Actions\ReplaceStaleCodingContainersAction;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesAgentMachines;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\Intelligence\Integration\Harness\Fakes\FakeCodingSshClient;
use Tests\TestCase;

/**
 * An image upgrade has to reach containers that already exist, and only ours, and not the ones
 * someone is working in.
 */
class ReplaceStaleCodingContainersTest extends TestCase
{
    use CreatesAgentMachines;
    use CreatesTaskSessions;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private const string NEW_IMAGE = 'kanvas/opencode:2.0.17';
    private const string OLD_IMAGE = 'kanvas/opencode:2.0.16';
    private const string CONTAINER = 'kanvas-coding-agent-7100';
    private const int AGENT = 7100;

    public function testAnIdleContainerOnAnOlderImageIsRemoved(): void
    {
        $machine = $this->createAgentMachine();
        $this->sessionOn($machine, HarnessStatusEnum::COMPLETED);
        $ssh = $this->sshListing(self::CONTAINER . '|' . self::AGENT . '|' . self::OLD_IMAGE);

        $result = $this->replace($machine, $ssh)->execute();

        $this->assertSame([self::CONTAINER], $result['replaced']);
        $this->assertSame([], $result['kept']);
        $this->assertSame([self::CONTAINER], $ssh->removedContainers());
    }

    /**
     * A tag rebuilt in place leaves the running container pointing at the old image id, and docker
     * reports that id instead of the tag — which is stale by any reading.
     */
    public function testAContainerOnARebuiltImageIdIsRemoved(): void
    {
        $machine = $this->createAgentMachine();
        $this->sessionOn($machine, HarnessStatusEnum::COMPLETED);
        $ssh = $this->sshListing(self::CONTAINER . '|' . self::AGENT . '|sha256:0f3a');

        $this->replace($machine, $ssh)->execute();

        $this->assertSame([self::CONTAINER], $ssh->removedContainers());
    }

    public function testAContainerAlreadyOnTheNewImageIsLeftAlone(): void
    {
        $machine = $this->createAgentMachine();
        $this->sessionOn($machine, HarnessStatusEnum::COMPLETED);
        $ssh = $this->sshListing(self::CONTAINER . '|' . self::AGENT . '|' . self::NEW_IMAGE);

        $result = $this->replace($machine, $ssh)->execute();

        $this->assertSame([], $result['replaced']);
        $this->assertSame([], $ssh->removedContainers());
    }

    /**
     * The label names an agent, not a runtime. A container whose agent never ran a coding session on
     * this machine is somebody else's, however it is labelled.
     */
    public function testAContainerWhoseAgentHasNoSessionOnThisMachineIsNotOurs(): void
    {
        $machine = $this->createAgentMachine();
        $this->sessionOn($machine, HarnessStatusEnum::COMPLETED);
        $ssh = $this->sshListing(
            self::CONTAINER . '|' . self::AGENT . '|' . self::OLD_IMAGE . "\n"
            . 'kanvas-coding-agent-7200|7200|' . self::OLD_IMAGE
        );

        $result = $this->replace($machine, $ssh)->execute();

        $this->assertSame([self::CONTAINER], $result['replaced']);
        $this->assertSame([self::CONTAINER], $ssh->removedContainers());
    }

    public function testAContainerWithAJobRunningIsKeptUnlessForced(): void
    {
        $machine = $this->createAgentMachine();
        $this->sessionOn($machine, HarnessStatusEnum::RUNNING);
        $ssh = $this->sshListing(self::CONTAINER . '|' . self::AGENT . '|' . self::OLD_IMAGE);

        $result = $this->replace($machine, $ssh)->execute();

        $this->assertSame([], $result['replaced']);
        $this->assertSame([self::CONTAINER], $result['kept']);
        $this->assertSame([], $ssh->removedContainers());
    }

    public function testForcingReplacesABusyContainerAndClosesItsSessions(): void
    {
        $machine = $this->createAgentMachine();
        $session = $this->sessionOn($machine, HarnessStatusEnum::RUNNING);
        $ssh = $this->sshListing(self::CONTAINER . '|' . self::AGENT . '|' . self::OLD_IMAGE);

        $result = $this->replace($machine, $ssh, force: true)->execute();

        $this->assertSame([self::CONTAINER], $result['replaced']);
        $this->assertSame(1, $result['sessions_closed']);
        $this->assertSame([self::CONTAINER], $ssh->removedContainers());
        $this->assertContains($session->refresh()->status, HarnessStatusEnum::terminalValues());
    }

    public function testADryRunReportsButRemovesNothing(): void
    {
        $machine = $this->createAgentMachine();
        $this->sessionOn($machine, HarnessStatusEnum::COMPLETED);
        $ssh = $this->sshListing(self::CONTAINER . '|' . self::AGENT . '|' . self::OLD_IMAGE);

        $result = $this->replace($machine, $ssh, dryRun: true)->execute();

        $this->assertSame([self::CONTAINER], $result['replaced']);
        $this->assertSame([], $ssh->removedContainers());
    }

    public function testAMachineThatNeverHostedASessionIsNotConnectedTo(): void
    {
        $machine = $this->createAgentMachine();
        $ssh = $this->sshListing(self::CONTAINER . '|' . self::AGENT . '|' . self::OLD_IMAGE);

        $result = $this->replace($machine, $ssh)->execute();

        $this->assertSame(['replaced' => [], 'kept' => [], 'sessions_closed' => 0], $result);
        $this->assertSame([], $ssh->commands);
    }

    private function sessionOn(AgentMachine $machine, HarnessStatusEnum $status)
    {
        return $this->createTaskSession($status, [
            'agent_id' => self::AGENT,
            'agent_machine_id' => $machine->getId(),
            'container_name' => self::CONTAINER,
        ]);
    }

    private function sshListing(string $listing): FakeCodingSshClient
    {
        return new FakeCodingSshClient(['docker ps -a' => $listing]);
    }

    private function replace(
        AgentMachine $machine,
        FakeCodingSshClient $ssh,
        bool $force = false,
        bool $dryRun = false
    ): ReplaceStaleCodingContainersAction {
        return new class (
            $machine,
            self::NEW_IMAGE,
            $ssh,
            $force,
            $dryRun
        ) extends ReplaceStaleCodingContainersAction {
            public function __construct(
                AgentMachine $machine,
                string $image,
                private readonly FakeCodingSshClient $ssh,
                bool $force,
                bool $dryRun
            ) {
                parent::__construct($machine, $image, $force, $dryRun);
            }

            protected function openSshClient(AgentMachine $machine): SshClient
            {
                return $this->ssh;
            }
        };
    }
}
