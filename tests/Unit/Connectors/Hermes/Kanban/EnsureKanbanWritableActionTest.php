<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Hermes\Kanban;

use Kanvas\Connectors\Hermes\Kanban\Actions\EnsureKanbanWritableAction;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Mockery;
use PHPUnit\Framework\TestCase;

final class EnsureKanbanWritableActionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testChmodsWhenDirIsNot777(): void
    {
        $ssh = new FakeStatSshClient('755');

        new TestableEnsureKanbanWritableAction($this->deployment(), $ssh)->execute();

        $this->assertStringContainsString('stat -c %a /opt/data/kanban', $ssh->commands[0]);
        $this->assertStringContainsString('chmod -R 777 /opt/data/kanban', $ssh->commands[1] ?? '');
        $this->assertStringContainsString('docker exec -u root', $ssh->commands[1] ?? '');
    }

    public function testSkipsChmodWhenAlready777(): void
    {
        $ssh = new FakeStatSshClient('777');

        new TestableEnsureKanbanWritableAction($this->deployment(), $ssh)->execute();

        $this->assertCount(1, $ssh->commands); // only the stat check, no chmod
    }

    private function deployment(): AgentDeployment
    {
        $machine = Mockery::mock(AgentMachine::class);
        $deployment = Mockery::mock(AgentDeployment::class)->makePartial();
        $deployment->shouldReceive('getAttribute')->with('machine')->andReturn($machine);
        $deployment->shouldReceive('getAttribute')->with('container_name')->andReturn('hermes-bot');

        return $deployment;
    }
}

class TestableEnsureKanbanWritableAction extends EnsureKanbanWritableAction
{
    public function __construct(AgentDeployment $deployment, private FakeStatSshClient $fake)
    {
        parent::__construct($deployment);
    }

    protected function openSshClient(AgentMachine $machine): SshClient
    {
        return $this->fake;
    }
}

class FakeStatSshClient extends SshClient
{
    /** @var list<string> */
    public array $commands = [];

    public function __construct(private readonly string $statMode)
    {
        // never open a real socket
    }

    public function exec(string $command, int $timeout = 30): string
    {
        $this->commands[] = $command;

        return str_contains($command, 'stat -c') ? $this->statMode : '';
    }

    public function disconnect(): void
    {
    }
}
