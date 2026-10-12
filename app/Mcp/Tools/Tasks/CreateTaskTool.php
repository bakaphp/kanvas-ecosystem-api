<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Tasks;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\Concerns\PresentsProjectBoard;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Models\Task;
use Laravel\Mcp\Request;
use Override;

class CreateTaskTool extends KanvasTool
{
    use PresentsProjectBoard;

    /** The plan_type the project board files its own cards under. */
    private const string PLAN_TYPE = 'project_work';

    protected string $name = 'create_task';

    protected string $description = 'Create a task (a card) on a project board, optionally with its subtasks in one '
        . 'call. Pass board_column_key to drop it in a specific column; without it the task lands in the '
        . 'first column (TODO).';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('The project the task belongs to.')->required(),
            'title' => $schema->string()->description('Short task title.')->required(),
            'description' => $schema->string()->description('What needs to be done.'),
            'board_column_key' => $schema->string()
                ->description('Column to place the task in, from list_board_columns. The task takes that column\'s status.'),
            'priority' => $schema->integer()->description('Higher sorts first. Defaults to 0.'),
            'deadline_at' => $schema->string()->description('Deadline as an ISO-8601 date or datetime.'),
            'agent_id' => $schema->integer()
                ->description('Agent to assign the task to, from list_agents. Unassigned tasks belong to you.'),
            'subtasks' => $schema->array()
                ->items($schema->object([
                    'title' => $schema->string()->description('Subtask title.')->required(),
                    'description' => $schema->string()->description('Subtask details.'),
                ]))
                ->description('Subtasks to create with the task, in order.'),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $input = $request->validate([
            'project_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'board_column_key' => ['nullable', 'string'],
            'priority' => ['nullable', 'integer'],
            'deadline_at' => ['nullable', 'date'],
            'agent_id' => ['nullable', 'integer'],
            'subtasks' => ['nullable', 'array'],
            'subtasks.*.title' => ['required', 'string', 'max:255'],
            'subtasks.*.description' => ['nullable', 'string'],
        ]);

        $plan = new CreatePlanAction(
            PlanData::from(
                $ctx->app,
                $ctx->user,
                $ctx->company,
                ['plan_type' => self::PLAN_TYPE] + $input,
            ),
            tasks: TaskData::fromMultipleArray(plan: null, rows: $input['subtasks'] ?? []),
        )->execute();

        return [
            'task' => $this->presentTask($plan),
            'subtasks' => $plan->tasks()
                ->orderBy('sequence')
                ->get()
                ->map(fn (Task $task): array => $this->presentSubtask($task))
                ->all(),
        ];
    }
}
