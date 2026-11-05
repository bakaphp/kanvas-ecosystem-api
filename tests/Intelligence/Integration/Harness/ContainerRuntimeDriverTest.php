<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Runtime\ContainerRuntimeDriver;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\RuntimeHandle;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\Intelligence\Integration\Harness\Concerns\MocksCodingApp;
use Tests\Intelligence\Integration\Harness\Fakes\FakeCodingSshClient;
use Tests\TestCase;

/**
 * An agent's container is reused across tasks, so a key rotation or an image upgrade only takes
 * effect if `ensure()` refuses the container that is already running. Driven through the public
 * method with a fake host, so the docker conversation itself is what is pinned.
 */
class ContainerRuntimeDriverTest extends TestCase
{
    use DatabaseTransactions;
    use MocksCodingApp;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private const string IMAGE = 'kanvas/opencode:2.0.16';

    public function testARunningContainerOnTheConfiguredImageWithTheCurrentKeyIsReused(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh($this->handle($agent) . '|' . $this->fingerprint($agent) . '|' . self::IMAGE);

        $runtime = $this->driver()->ensure($agent, $ssh);

        $this->assertSame($this->handle($agent), $runtime->handle);
        $this->assertSame('pw-old', $runtime->password);
        $this->assertSame(MachineRuntimeEnum::CONTAINER, $runtime->runtime);
        $this->assertFalse($this->started($ssh));
    }

    public function testAContainerOnAnOlderImageIsReplaced(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh($this->handle($agent) . '|' . $this->fingerprint($agent) . '|kanvas/opencode:2.0.15');

        $runtime = $this->driver()->ensure($agent, $ssh);

        $this->assertTrue($this->started($ssh));
        $this->assertSame([$this->handle($agent)], $ssh->removedContainers());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $runtime->password);
        $this->assertNotSame('pw-old', $runtime->password);
        $this->assertSame($runtime->password, $agent->get(AgentCustomFieldEnum::CONTAINER_PASSWORD->value));
    }

    /**
     * Docker shows the bare image id once the tag has been rebuilt under the container.
     */
    public function testAContainerOnARebuiltTagIsReplaced(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh($this->handle($agent) . '|' . $this->fingerprint($agent) . '|sha256:9c1d');

        $this->driver()->ensure($agent, $ssh);

        $this->assertTrue($this->started($ssh));
    }

    public function testAContainerStartedWithAnOlderKeyIsReplaced(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh($this->handle($agent) . '|0000000000000000|' . self::IMAGE);

        $this->driver()->ensure($agent, $ssh);

        $this->assertTrue($this->started($ssh));
    }

    public function testNoRunningContainerStartsOne(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh('');

        $this->driver()->ensure($agent, $ssh);

        $run = $ssh->commandStartingWith('docker run');
        $this->assertStringContainsString('--name ' . escapeshellarg($this->handle($agent)), $run);
        $this->assertStringContainsString('--label ' . escapeshellarg('kanvas.keyfp=' . $this->fingerprint($agent)), $run);
        $this->assertStringContainsString('-e ' . escapeshellarg('HOME=/workspaces/.home'), $run);
        $this->assertStringContainsString('-v ' . escapeshellarg('/srv/kanvas/agents/' . $agent->getId() . '/worktrees:/workspaces'), $run);
        $this->assertStringEndsWith(escapeshellarg(self::IMAGE) . ' 2>&1; echo "EXIT_CODE:$?"', $run);
    }

    public function testTheCapRefusesANewContainer(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh('', $this->othersAtCap());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('2-agent cap');

        try {
            $this->driver()->ensure($agent, $ssh);
        } finally {
            $this->assertFalse($this->started($ssh));
        }
    }

    public function testAnAgentsOwnContainerIsReusedAtTheCap(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh(
            $this->handle($agent) . '|' . $this->fingerprint($agent) . '|' . self::IMAGE,
            $this->othersAtCap() . "\n" . $this->handle($agent) . '|' . $agent->getId() . '|' . self::IMAGE
        );

        $runtime = $this->driver()->ensure($agent, $ssh);

        $this->assertSame($this->handle($agent), $runtime->handle);
        $this->assertFalse($this->started($ssh));
    }

    /**
     * Rolling an agent's own stale container over adds nothing to the box, so a full machine must
     * still let it happen — otherwise an image upgrade is refused for every agent on exactly the
     * machines the operator most wants moved.
     */
    public function testAnAgentsOwnStaleContainerIsReplacedAtTheCap(): void
    {
        $agent = $this->codingAgent();
        $ssh = $this->ssh(
            $this->handle($agent) . '|' . $this->fingerprint($agent) . '|kanvas/opencode:2.0.15',
            $this->othersAtCap() . "\n" . $this->handle($agent) . '|' . $agent->getId() . '|kanvas/opencode:2.0.15'
        );

        $this->driver()->ensure($agent, $ssh);

        $this->assertTrue($this->started($ssh));
    }

    public function testSessionDirectoryIsTheMountedPathInAContainerAndTheHostPathOnAHost(): void
    {
        $workspace = '/srv/kanvas/agents/1/worktrees/abc';

        $this->assertSame('/workspaces/abc', $this->handleFor(MachineRuntimeEnum::CONTAINER)->sessionDirectory($workspace));
        $this->assertSame($workspace, $this->handleFor(MachineRuntimeEnum::HOST)->sessionDirectory($workspace));
    }

    public function testListParsesEveryLabelledContainer(): void
    {
        $ssh = new FakeCodingSshClient(['docker ps -a' => "a|7|img\nnot-ours||img\nb|9|sha256:0f"]);

        $this->assertSame(
            [
                ['handle' => 'a', 'agentId' => 7, 'version' => 'img'],
                ['handle' => 'b', 'agentId' => 9, 'version' => 'sha256:0f'],
            ],
            $this->driver()->list($ssh)
        );
    }

    public function testRemoveReportsWhetherTheNameCameFree(): void
    {
        $gone = new FakeCodingSshClient(['docker ps -aq' => '']);
        $this->assertTrue($this->driver()->remove('c', $gone));
        $this->assertSame(['c'], $gone->removedContainers());

        $stuck = new FakeCodingSshClient(['docker ps -aq' => 'abc123']);
        $this->assertFalse($this->driver()->remove('c', $stuck));
    }

    private function driver(): ContainerRuntimeDriver
    {
        $machine = new AgentMachine();
        $machine->name = 'box';
        $machine->max_agents = 2;

        return new class ($machine) extends ContainerRuntimeDriver {
            protected function pollDelaySeconds(): int
            {
                return 0;
            }
        };
    }

    private function codingAgent(): Agent
    {
        return $this->agent([AgentCustomFieldEnum::CONTAINER_PASSWORD->value => 'pw-old']);
    }

    private function handle(Agent $agent): string
    {
        return 'kanvas-coding-agent-' . $agent->getId();
    }

    private function othersAtCap(): string
    {
        return 'kanvas-coding-agent-1|1|' . self::IMAGE . "\nkanvas-coding-agent-2|2|" . self::IMAGE;
    }

    private function ssh(string $running, string $all = ''): FakeCodingSshClient
    {
        return new FakeCodingSshClient([
            'docker ps -aq' => '',
            'docker ps -a' => $all,
            'docker ps' => $running,
            'docker run' => 'EXIT_CODE:0',
            'test -w' => 'WRITABLE',
            'echo "$(id -u)' => '1000:1000',
        ]);
    }

    private function started(FakeCodingSshClient $ssh): bool
    {
        return $ssh->commandStartingWith('docker run') !== '';
    }

    private function handleFor(MachineRuntimeEnum $runtime): RuntimeHandle
    {
        return new RuntimeHandle(
            runtime: $runtime,
            handle: 'c',
            endpoint: 'http://127.0.0.1:4096',
            port: null,
            password: 'pw',
            worktreeRoot: '/srv/kanvas/agents/1/worktrees',
        );
    }
}
