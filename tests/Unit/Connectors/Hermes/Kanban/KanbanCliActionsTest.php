<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Hermes\Kanban;

use Kanvas\Connectors\Hermes\Kanban\Actions\CreateKanbanTaskAction;
use Kanvas\Connectors\Hermes\Kanban\Actions\FetchKanbanBoardAction;
use Kanvas\Connectors\Hermes\Kanban\Actions\TransitionKanbanTaskAction;
use Kanvas\Connectors\Hermes\Services\HermesContainerCliService;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTaskInput;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanTransition;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Mockery;
use PHPUnit\Framework\TestCase;

final class KanbanCliActionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testRunnerBuildsDockerExecAsHermesUserAndDecodesJson(): void
    {
        $ssh = new FakeKanbanSshClient();
        $ssh->listJson = '[{"id":"t_1"}]';

        $runner = new HermesContainerCliService($ssh, 'hermes-bot');
        $result = $runner->runJson(['kanban', '--board', 'sales', 'list', '--archived']);

        $this->assertSame([['id' => 't_1']], $result);

        $cmd = $ssh->commands[0];
        $this->assertStringContainsString('docker exec -u hermes', $cmd);
        $this->assertStringContainsString("'hermes-bot'", $cmd);
        $this->assertStringContainsString(HermesContainerCliService::DEFAULT_BINARY, $cmd);
        $this->assertStringContainsString("'kanban'", $cmd);
        $this->assertStringContainsString("'--board' 'sales'", $cmd);
        $this->assertStringContainsString("'list' '--archived'", $cmd);
        $this->assertStringContainsString("'--json'", $cmd);
    }

    public function testRunnerStripsNonJsonPreamble(): void
    {
        $ssh = new FakeKanbanSshClient();
        $ssh->forceResponse = "(node:1) ExperimentalWarning: blah\n[{\"id\":\"t_1\"}]";

        $runner = new HermesContainerCliService($ssh, 'hermes-bot');

        $this->assertSame([['id' => 't_1']], $runner->runJson(['list']));
    }

    public function testRunnerThrowsOnNonJsonOutput(): void
    {
        $ssh = new FakeKanbanSshClient();
        $ssh->forceResponse = 'command not found';

        $runner = new HermesContainerCliService($ssh, 'hermes-bot');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('non-JSON');

        $runner->runJson(['list']);
    }

    public function testFetchBuildsTreeViaListThenShowPerTask(): void
    {
        $ssh = new FakeKanbanSshClient();
        $ssh->listJson = '[{"id":"t_root"},{"id":"t_a"}]';
        $ssh->showById = [
            't_root' => json_encode([
                'task' => ['id' => 't_root', 'title' => 'Plan', 'status' => 'ready', 'assignee' => 'researcher'],
                'parents' => [],
                'children' => ['t_a'],
                'latest_summary' => 'done',
                'runs' => [],
            ]),
            't_a' => json_encode([
                'task' => ['id' => 't_a', 'title' => 'research X', 'status' => 'running'],
                'parents' => ['t_root'],
                'children' => [],
                'runs' => [],
            ]),
        ];

        $action = new TestableFetchKanbanBoardAction($this->deployment(), 'researcher', $ssh);
        $tasks = $action->execute();

        $this->assertCount(2, $tasks);
        $this->assertTrue($tasks[0]->isRoot());
        $this->assertSame(KanbanStatusEnum::READY, $tasks[0]->status);
        $this->assertFalse($tasks[1]->isRoot());
        $this->assertSame(['t_root'], $tasks[1]->parentIds);

        $this->assertStringContainsString("'list' '--archived'", $ssh->commands[0]);
        $this->assertStringContainsString("'--assignee' 'researcher'", $ssh->commands[0]);
        $this->assertStringContainsString("'show' 't_root'", $ssh->commands[1]);
    }

    public function testCreateRootTaskSendsIdempotencyKeyAndNoTriageNoParent(): void
    {
        $ssh = new FakeKanbanSshClient();
        $ssh->createJson = '{"id":"t_new","title":"Plan","status":"ready","assignee":"researcher"}';

        $input = new KanbanTaskInput(
            title: 'Plan: research the market',
            assignee: 'researcher',
            idempotencyKey: 'kanvas-uuid-1',
            priority: 2,
        );

        $action = new TestableCreateKanbanTaskAction($this->deployment(), $input, $ssh);
        $task = $action->execute();

        $this->assertSame('t_new', $task->id);

        $cmd = $ssh->commands[0];
        $this->assertStringContainsString("'create' 'Plan: research the market'", $cmd);
        $this->assertStringContainsString("'--assignee' 'researcher'", $cmd);
        $this->assertStringContainsString("'--idempotency-key' 'kanvas-uuid-1'", $cmd);
        $this->assertStringContainsString("'--priority' '2'", $cmd);
        $this->assertStringNotContainsString('--triage', $cmd);
        $this->assertStringNotContainsString('--parent', $cmd);
    }

    public function testCreateChildTaskSendsParentEdge(): void
    {
        $ssh = new FakeKanbanSshClient();
        $ssh->createJson = '{"id":"t_child","title":"research X","status":"todo"}';

        $input = new KanbanTaskInput(
            title: 'research X',
            assignee: 'researcher',
            idempotencyKey: 'kanvas-uuid-2',
            parentIds: ['t_root'],
        );

        new TestableCreateKanbanTaskAction($this->deployment(), $input, $ssh)->execute();

        $this->assertStringContainsString("'--parent' 't_root'", $ssh->commands[0]);
    }

    public function testTransitionArchiveRunsVerbThenRereadsViaShow(): void
    {
        $ssh = new FakeKanbanSshClient();
        $ssh->verbReturn = 'Archived t_x';
        $ssh->showById = ['t_x' => json_encode(['task' => ['id' => 't_x', 'title' => 'x', 'status' => 'archived']])];

        $action = new TestableTransitionKanbanTaskAction(
            $this->deployment(),
            't_x',
            KanbanTransition::ARCHIVE,
            $ssh,
        );
        $task = $action->execute();

        $this->assertSame(KanbanStatusEnum::ARCHIVED, $task->status);
        $this->assertStringContainsString("'archive' 't_x'", $ssh->commands[0]);
        $this->assertStringContainsString("'show' 't_x'", $ssh->commands[1]);
    }

    public function testTransitionCompleteCarriesResultAndBlockCarriesReason(): void
    {
        $ssh = new FakeKanbanSshClient();
        $ssh->showById = ['t_x' => json_encode(['task' => ['id' => 't_x', 'title' => 'x', 'status' => 'done']])];

        new TestableTransitionKanbanTaskAction(
            $this->deployment(),
            't_x',
            KanbanTransition::COMPLETE,
            $ssh,
            result: 'wrapped up',
        )->execute();
        $this->assertStringContainsString("'complete' 't_x' '--result' 'wrapped up'", $ssh->commands[0]);

        $ssh2 = new FakeKanbanSshClient();
        $ssh2->showById = ['t_x' => json_encode(['task' => ['id' => 't_x', 'title' => 'x', 'status' => 'blocked']])];
        new TestableTransitionKanbanTaskAction(
            $this->deployment(),
            't_x',
            KanbanTransition::BLOCK,
            $ssh2,
            reason: 'need input',
        )->execute();
        $this->assertStringContainsString("'block' 't_x' 'need input'", $ssh2->commands[0]);
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

/**
 * Routes canned responses by the kanban verb present in the (escaped) command, and records
 * every command issued so tests can assert the exact `docker exec … hermes kanban …` string.
 */
class FakeKanbanSshClient extends SshClient
{
    /** @var list<string> */
    public array $commands = [];
    public string $listJson = '[]';
    /** @var array<string, string> */
    public array $showById = [];
    public string $createJson = '{}';
    public string $verbReturn = '';
    public ?string $forceResponse = null;

    public function __construct()
    {
        // never open a real socket
    }

    public function exec(string $command, int $timeout = 30): string
    {
        $this->commands[] = $command;

        if ($this->forceResponse !== null) {
            return $this->forceResponse;
        }

        if (str_contains($command, "'list'")) {
            return $this->listJson;
        }

        if (str_contains($command, "'show'")) {
            foreach ($this->showById as $id => $json) {
                if (str_contains($command, "'" . $id . "'")) {
                    return $json;
                }
            }

            return '{}';
        }

        if (str_contains($command, "'create'")) {
            return $this->createJson;
        }

        return $this->verbReturn;
    }

    public function disconnect(): void
    {
    }
}

class TestableFetchKanbanBoardAction extends FetchKanbanBoardAction
{
    public function __construct(AgentDeployment $deployment, ?string $assignee, private FakeKanbanSshClient $fake)
    {
        parent::__construct($deployment, $assignee);
    }

    protected function openSshClient(AgentMachine $machine): BaseSshClient
    {
        return $this->fake;
    }
}

class TestableCreateKanbanTaskAction extends CreateKanbanTaskAction
{
    public function __construct(AgentDeployment $deployment, KanbanTaskInput $input, private FakeKanbanSshClient $fake)
    {
        parent::__construct($deployment, $input);
    }

    protected function openSshClient(AgentMachine $machine): BaseSshClient
    {
        return $this->fake;
    }
}

class TestableTransitionKanbanTaskAction extends TransitionKanbanTaskAction
{
    public function __construct(
        AgentDeployment $deployment,
        string $externalTaskId,
        KanbanTransition $transition,
        private FakeKanbanSshClient $fake,
        ?string $reason = null,
        ?string $assignee = null,
        ?string $result = null,
    ) {
        parent::__construct($deployment, $externalTaskId, $transition, $reason, $assignee, $result);
    }

    protected function openSshClient(AgentMachine $machine): BaseSshClient
    {
        return $this->fake;
    }
}
