<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\OpenCode\Actions\ReapOrphanCodingContainersAction;
use Kanvas\Connectors\OpenCode\Enums\MachineCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Runtime\CodingRuntimeDriverFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\CodingRuntimeDriver;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesAgentMachines;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\Intelligence\Integration\Harness\Fakes\FakeCodingSshClient;
use Tests\Intelligence\Integration\Harness\Fakes\ImpatientContainerRuntimeDriver;
use Tests\TestCase;

/**
 * The disk series the reaper prints has to mean the same thing on a Linux box and a Mac, and the
 * host runtime's logs are the one thing on a Mac nothing else reclaims.
 */
class ReapOrphanCodingContainersTest extends TestCase
{
    use CreatesAgentMachines;
    use CreatesTaskSessions;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testDiskIsReportedInBytesFromKilobyteTools(): void
    {
        $machine = $this->createAgentMachine();
        $this->sessionOn($machine);
        $ssh = new FakeCodingSshClient(['echo "total:' => "total:2048\nhome:1024\navailable:4096"]);

        $result = $this->reap($machine, $ssh)->execute();

        $this->assertSame(['home' => 1024, 'total' => 2048, 'available' => 4096, 'xcode' => 0], $result['disk']);
        $disk = $ssh->commandStartingWith('echo "total:');
        $this->assertStringContainsString('du -sk', $disk);
        $this->assertStringContainsString('df -kP', $disk);
        $this->assertStringNotContainsString('du -sbc', $disk);
    }

    public function testTheHostDriverTruncatesLogsOnTheSweep(): void
    {
        $machine = $this->createAgentMachine();
        $machine->runtime = 'host';
        $machine->saveOrFail();
        $this->sessionOn($machine);
        $ssh = new FakeCodingSshClient([
            'echo "$HOME|' => '/Users/kanvasrun|501|kanvasrun',
            'find ' => '/srv/kanvas/agents/7/log/opencode.err.log',
        ]);

        $result = $this->reap($machine, $ssh)->execute();

        $this->assertSame(['/srv/kanvas/agents/7/log/opencode.err.log'], $result['housekeeping']);
    }

    public function testXcodeDiskIsMeasuredOnAHostWithXcode(): void
    {
        $machine = $this->createAgentMachine();
        $machine->runtime = 'host';
        $machine->saveOrFail();
        $machine->set(MachineCustomFieldEnum::HOST_TOOLS->value, ['xcode']);
        $this->sessionOn($machine);
        $ssh = new FakeCodingSshClient([
            'echo "$HOME|' => '/Users/kanvasrun|501|kanvasrun',
            'echo "total:' => "total:2048\nhome:1024\navailable:4096\nxcode:3072",
        ]);

        $result = $this->reap($machine, $ssh)->execute();

        $this->assertSame(3072, $result['disk']['xcode']);
        $disk = $ssh->commandStartingWith('echo "total:');
        $this->assertStringContainsString('echo "xcode:', $disk);
        $this->assertStringContainsString('/Users/kanvasrun/Library/Developer/Xcode/DerivedData', $disk);
    }

    /**
     * A host records what a session left running in pid files inside its workspace, so the sweep that
     * stops those processes has to run before the workspace is removed.
     */
    public function testHousekeepingRunsBeforeExpiredWorkspacesAreRemoved(): void
    {
        $machine = $this->createAgentMachine(runtime: 'host');
        $this->sessionOn($machine, hoursAgo: 48);
        $ssh = new FakeCodingSshClient(['echo "$HOME|' => '/Users/kanvasrun|501|kanvasrun']);

        $result = $this->reap($machine, $ssh)->execute();

        $this->assertSame(['/srv/kanvas/agents/7/worktrees/u1'], $result['workspaces']);
        $sweep = array_search(true, array_map(static fn (string $c): bool => str_starts_with($c, 'find '), $ssh->commands), true);
        $removal = array_search(true, array_map(static fn (string $c): bool => str_starts_with($c, 'rm -rf '), $ssh->commands), true);
        $this->assertNotFalse($sweep);
        $this->assertNotFalse($removal);
        $this->assertLessThan($removal, $sweep);
    }

    public function testAContainerMachineHasNoLogsToTruncate(): void
    {
        $machine = $this->createAgentMachine();
        $this->sessionOn($machine);
        $ssh = new FakeCodingSshClient([]);

        $result = $this->reap($machine, $ssh)->execute();

        $this->assertSame([], $result['housekeeping']);
        $this->assertSame('', $ssh->commandStartingWith('find '));
    }

    /**
     * `docker rm -f` returns before the name is free, and a container that never frees it is still
     * there for the next sweep; reporting it removed would hide that from whoever reads the log.
     */
    public function testAContainerThatWillNotGoIsNotReportedAsRemoved(): void
    {
        $machine = $this->createAgentMachine();
        $this->sessionOn($machine, hoursAgo: 48);
        $ssh = new FakeCodingSshClient([
            'docker ps -a' => 'kanvas-coding-7|7|kanvas/opencode:2.2.5',
            'docker ps -aq' => 'abc123',
        ]);

        $result = $this->reap($machine, $ssh)->execute();

        $this->assertSame([], $result['containers']);
        $this->assertSame(['kanvas-coding-7'], $ssh->removedContainers());
    }

    private function sessionOn(AgentMachine $machine, int $hoursAgo = 0): void
    {
        $this->createTaskSession(HarnessStatusEnum::COMPLETED, [
            'agent_id' => 7,
            'agent_machine_id' => $machine->getId(),
            'container_name' => 'kanvas-coding-7',
            'workspace_path' => '/srv/kanvas/agents/7/worktrees/u1',
            'completed_at' => Carbon::now()->subHours($hoursAgo),
            'heartbeat_at' => Carbon::now()->subHours($hoursAgo),
        ]);
    }

    private function reap(AgentMachine $machine, FakeCodingSshClient $ssh): ReapOrphanCodingContainersAction
    {
        return new class ($machine, $ssh, $this->impatientDriver($machine)) extends ReapOrphanCodingContainersAction {
            public function __construct(
                AgentMachine $machine,
                private readonly FakeCodingSshClient $ssh,
                private readonly CodingRuntimeDriver $driver
            ) {
                parent::__construct($machine);
            }

            protected function openSshClient(): SshClient
            {
                return $this->ssh;
            }

            protected function driver(): CodingRuntimeDriver
            {
                return $this->driver;
            }
        };
    }

    /**
     * The machine's own driver, except that a container driver polls without sleeping, so a container
     * that refuses to go is reported in milliseconds rather than after the real fifteen-second grace.
     */
    private function impatientDriver(AgentMachine $machine): CodingRuntimeDriver
    {
        if ($machine->runtimeMode() !== MachineRuntimeEnum::CONTAINER) {
            return CodingRuntimeDriverFactory::forMachine($machine);
        }

        return new ImpatientContainerRuntimeDriver($machine);
    }
}
