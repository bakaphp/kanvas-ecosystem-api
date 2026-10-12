<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use App\Mcp\Servers\ProjectServer;
use App\Mcp\Tools\Attachments\AttachFileTool;
use App\Mcp\Tools\Attachments\ListFilesTool;
use App\Mcp\Tools\Columns\CreateBoardColumnTool;
use App\Mcp\Tools\Columns\ListBoardColumnsTool;
use App\Mcp\Tools\Columns\RenameBoardColumnTool;
use App\Mcp\Tools\Columns\ReorderBoardColumnsTool;
use App\Mcp\Tools\Projects\CreateProjectTool;
use App\Mcp\Tools\Projects\DeleteProjectTool;
use App\Mcp\Tools\Projects\GetProjectTool;
use App\Mcp\Tools\Projects\ListAgentsTool;
use App\Mcp\Tools\Projects\ListProjectsTool;
use App\Mcp\Tools\Projects\UpdateProjectTool;
use App\Mcp\Tools\Subtasks\AddSubtaskTool;
use App\Mcp\Tools\Subtasks\DeleteSubtaskTool;
use App\Mcp\Tools\Subtasks\UpdateSubtaskStatusTool;
use App\Mcp\Tools\Tasks\CreateTaskTool;
use App\Mcp\Tools\Tasks\DeleteTaskTool;
use App\Mcp\Tools\Tasks\GetTaskTool;
use App\Mcp\Tools\Tasks\ListTasksTool;
use App\Mcp\Tools\Tasks\UpdateTaskTool;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ProjectMcpServerTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * @param class-string $tool
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $tool, array $arguments = []): array
    {
        $content = [];

        ProjectServer::tool($tool, $arguments)
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use (&$content): void {
                $content = $json->toArray();
                $json->etc();
            });

        return $content;
    }

    private function makeAgent(): Agent
    {
        /** @var Users $user */
        $user = auth()->user();

        return Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['user_id' => $user->getId(), 'is_active' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeProject(string $title = 'MCP board'): array
    {
        return $this->callTool(CreateProjectTool::class, [
            'title' => $title,
            'agent_id' => $this->makeAgent()->getId(),
        ])['project'];
    }

    public function testTheServerExposesEveryBoardTool(): void
    {
        ProjectServer::tools()->assertRegistered([
            ListProjectsTool::class,
            GetProjectTool::class,
            CreateProjectTool::class,
            UpdateProjectTool::class,
            DeleteProjectTool::class,
            ListAgentsTool::class,
            ListBoardColumnsTool::class,
            CreateBoardColumnTool::class,
            RenameBoardColumnTool::class,
            ReorderBoardColumnsTool::class,
            ListTasksTool::class,
            GetTaskTool::class,
            CreateTaskTool::class,
            UpdateTaskTool::class,
            DeleteTaskTool::class,
            AddSubtaskTool::class,
            UpdateSubtaskStatusTool::class,
            DeleteSubtaskTool::class,
            AttachFileTool::class,
            ListFilesTool::class,
        ]);
    }

    public function testAProjectCanBeCreatedFoundUpdatedAndDeleted(): void
    {
        $agent = $this->makeAgent();

        $agents = $this->callTool(ListAgentsTool::class, ['search' => $agent->name]);
        $this->assertContains($agent->getId(), array_column($agents['agents'], 'id'));

        $project = $this->callTool(CreateProjectTool::class, [
            'title' => 'Launch plan',
            'agent_id' => $agent->getId(),
            'objective' => 'Ship it',
        ])['project'];

        $this->assertSame('Launch plan', $project['title']);
        $this->assertSame('draft', $project['status']);

        $listed = $this->callTool(ListProjectsTool::class, ['search' => 'Launch plan']);
        $this->assertContains($project['id'], array_column($listed['projects'], 'id'));

        $updated = $this->callTool(UpdateProjectTool::class, [
            'project_id' => $project['id'],
            'status' => 'active',
        ])['project'];

        $this->assertSame('active', $updated['status']);
        $this->assertSame('Ship it', $updated['objective'], 'a field that was not sent keeps its value');

        $this->assertTrue($this->callTool(DeleteProjectTool::class, ['project_id' => $project['id']])['deleted']);
        $this->assertNull(Project::query()->find($project['id']));
    }

    public function testBoardColumnsCanBeAddedRenamedAndReordered(): void
    {
        $project = $this->makeProject();

        $defaults = $this->callTool(ListBoardColumnsTool::class, ['project_id' => $project['id']])['columns'];
        $this->assertSame(['todo', 'in_progress', 'blocked', 'done'], array_column($defaults, 'key'));

        $column = $this->callTool(CreateBoardColumnTool::class, [
            'project_id' => $project['id'],
            'name' => 'In review',
            'task_status' => 'active',
        ])['column'];

        $this->assertSame('in_review', $column['key']);
        $this->assertSame('active', $column['plan_status']);

        $renamed = $this->callTool(RenameBoardColumnTool::class, [
            'project_id' => $project['id'],
            'key' => 'in_review',
            'name' => 'QA',
        ])['column'];

        $this->assertSame('QA', $renamed['name']);
        $this->assertSame('in_review', $renamed['key'], 'renaming must not move the tasks already in the column');

        $order = ['todo', 'in_progress', 'in_review', 'blocked', 'done'];
        $reordered = $this->callTool(ReorderBoardColumnsTool::class, [
            'project_id' => $project['id'],
            'keys' => $order,
        ])['columns'];

        $this->assertSame($order, array_column($reordered, 'key'));
    }

    public function testReorderingWithAMissingColumnIsReportedAsAToolError(): void
    {
        $project = $this->makeProject();

        ProjectServer::tool(ReorderBoardColumnsTool::class, [
            'project_id' => $project['id'],
            'keys' => ['todo', 'done'],
        ])->assertHasErrors(['every existing column exactly once']);
    }

    public function testATaskIsCreatedWithSubtasksAndShowsUpInItsColumn(): void
    {
        $project = $this->makeProject();

        $created = $this->callTool(CreateTaskTool::class, [
            'project_id' => $project['id'],
            'title' => 'Write the brief',
            'subtasks' => [
                ['title' => 'Outline'],
                ['title' => 'Draft', 'description' => 'First pass'],
            ],
        ]);

        $this->assertSame('draft', $created['task']['status']);
        $this->assertSame(['Outline', 'Draft'], array_column($created['subtasks'], 'title'));

        $board = $this->callTool(GetProjectTool::class, ['project_id' => $project['id']]);
        $todo = collect($board['columns'])->firstWhere('key', 'todo');

        $this->assertSame(
            [$created['task']['id']],
            array_column($todo['tasks'], 'id'),
            'a task created without a column must still render in the column covering its status',
        );

        $inTodo = $this->callTool(ListTasksTool::class, ['project_id' => $project['id'], 'board_column_key' => 'todo']);
        $this->assertSame(1, $inTodo['total']);
        $this->assertSame('todo', $inTodo['tasks'][0]['board_column_key']);

        $inDone = $this->callTool(ListTasksTool::class, ['project_id' => $project['id'], 'board_column_key' => 'done']);
        $this->assertSame(0, $inDone['total']);
    }

    public function testMovingATaskToAColumnGivesItThatColumnsStatus(): void
    {
        $project = $this->makeProject();
        $task = $this->callTool(CreateTaskTool::class, [
            'project_id' => $project['id'],
            'title' => 'Move me',
        ])['task'];

        $moved = $this->callTool(UpdateTaskTool::class, [
            'task_id' => $task['id'],
            'board_column_key' => 'in_progress',
        ])['task'];

        $this->assertSame('in_progress', $moved['board_column_key']);
        $this->assertSame('active', $moved['status']);
        $this->assertSame('Move me', $moved['title']);
    }

    public function testSubtasksCanBeAddedCompletedAndDeleted(): void
    {
        $project = $this->makeProject();
        $task = $this->callTool(CreateTaskTool::class, [
            'project_id' => $project['id'],
            'title' => 'Parent',
        ])['task'];

        $first = $this->callTool(AddSubtaskTool::class, ['task_id' => $task['id'], 'title' => 'One'])['subtask'];
        $second = $this->callTool(AddSubtaskTool::class, ['task_id' => $task['id'], 'title' => 'Two'])['subtask'];

        $this->assertSame('pending', $first['status']);
        $this->assertGreaterThan($first['sequence'], $second['sequence']);

        $done = $this->callTool(UpdateSubtaskStatusTool::class, [
            'subtask_id' => $first['id'],
            'status' => 'done',
            'summary' => 'Shipped',
        ])['subtask'];

        $this->assertSame('done', $done['status']);
        $this->assertSame(50, Plan::query()->find($task['id'])->completion_pct);
        $this->assertSame(['result' => 'Shipped'], Task::query()->find($first['id'])->result);

        $this->assertTrue($this->callTool(DeleteSubtaskTool::class, ['subtask_id' => $second['id']])['deleted']);

        $read = $this->callTool(GetTaskTool::class, ['task_id' => $task['id']]);
        $this->assertSame([$first['id']], array_column($read['subtasks'], 'id'));
        $this->assertSame(100, $read['task']['completion_pct']);

        $this->assertTrue($this->callTool(DeleteTaskTool::class, ['task_id' => $task['id']])['deleted']);
        $this->assertNull(Task::query()->find($first['id']), 'deleting a task takes its subtasks');
    }

    public function testFilesAttachToATaskAndASubtaskAndRollUpOnTheTask(): void
    {
        $project = $this->makeProject();
        $created = $this->callTool(CreateTaskTool::class, [
            'project_id' => $project['id'],
            'title' => 'With files',
            'subtasks' => [['title' => 'Deliverable']],
        ]);
        $taskId = $created['task']['id'];
        $subtaskId = $created['subtasks'][0]['id'];

        $attached = $this->callTool(AttachFileTool::class, [
            'target_type' => 'task',
            'target_id' => $taskId,
            'file_name' => 'brief.md',
            'content' => '# Brief',
        ]);

        $this->assertSame('success', $attached['status']);
        $this->assertNotEmpty($attached['file_url']);

        $this->callTool(AttachFileTool::class, [
            'target_type' => 'subtask',
            'target_id' => $subtaskId,
            'file_name' => 'report.md',
            'content' => '# Report',
        ]);

        $files = $this->callTool(ListFilesTool::class, ['target_type' => 'task', 'target_id' => $taskId]);

        $this->assertSame(2, $files['count']);
        $this->assertSame(['brief.md', 'report.md'], array_column($files['files'], 'file_name'));
        $this->assertSame($subtaskId, $files['files'][1]['subtask_id']);

        $own = $this->callTool(ListFilesTool::class, ['target_type' => 'subtask', 'target_id' => $subtaskId]);
        $this->assertSame(['report.md'], array_column($own['files'], 'file_name'));
    }

    public function testAttachingWithoutContentOrUrlIsReportedAsAToolError(): void
    {
        $project = $this->makeProject();

        ProjectServer::tool(AttachFileTool::class, [
            'target_type' => 'project',
            'target_id' => $project['id'],
        ])->assertHasErrors(['Nothing to upload']);
    }

    public function testARecordFromAnotherCompanyIsNotReachable(): void
    {
        $project = $this->makeProject();
        $task = $this->callTool(CreateTaskTool::class, [
            'project_id' => $project['id'],
            'title' => 'Private',
        ])['task'];

        Project::query()->where('id', $project['id'])->update(['companies_id' => 999999999]);
        Plan::query()->where('id', $task['id'])->update(['companies_id' => 999999999]);

        ProjectServer::tool(GetProjectTool::class, ['project_id' => $project['id']])
            ->assertHasErrors(['Record not found in this company']);

        ProjectServer::tool(UpdateTaskTool::class, ['task_id' => $task['id'], 'title' => 'Hijacked'])
            ->assertHasErrors(['Record not found in this company']);

        $this->assertSame('Private', Plan::query()->find($task['id'])->title);
    }

    public function testTheEndpointRejectsARequestMissingAKanvasHeader(): void
    {
        $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

        $this->postJson('/v1/mcp/projects', $payload)
            ->assertUnauthorized()
            ->assertJson(['message' => 'Missing required header: ' . AppEnums::KANVAS_APP_HEADER->getValue()]);

        $headers = $this->kanvasHeaders();
        unset($headers[AppEnums::KANVAS_APP_BRANCH_HEADER->getValue()]);

        $this->postJson('/v1/mcp/projects', $payload, $headers)
            ->assertUnauthorized()
            ->assertJson(['message' => 'Missing required header: ' . AppEnums::KANVAS_APP_BRANCH_HEADER->getValue()]);
    }

    public function testTheEndpointServesToolsWhenAllKanvasHeadersResolve(): void
    {
        $response = $this->postJson(
            '/v1/mcp/projects',
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            $this->kanvasHeaders(),
        );

        $response->assertOk();
        $this->assertContains('create_task', array_column($response->json('result.tools'), 'name'));
        $this->assertCount(20, $response->json('result.tools'), 'every tool must fit on the first tools/list page');
    }

    /**
     * @return array<string, string>
     */
    private function kanvasHeaders(): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return [
            AppEnums::KANVAS_APP_HEADER->getValue() => $app->key,
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() => $user->getCurrentBranch()->uuid,
        ];
    }
}
