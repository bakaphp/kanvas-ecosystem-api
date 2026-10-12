<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Subtasks;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\Concerns\PresentsProjectBoard;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Plan\Actions\AddTaskAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Laravel\Mcp\Request;
use Override;

class AddSubtaskTool extends KanvasTool
{
    use PresentsProjectBoard;

    protected string $name = 'add_subtask';

    protected string $description = 'Add a subtask to a task. It is appended after the existing subtasks and starts as '
        . 'pending; the task\'s completion percentage is recalculated.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The task that gets the subtask.')->required(),
            'title' => $schema->string()->description('Short subtask title.')->required(),
            'description' => $schema->string()->description('What needs to be done.'),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $input = $request->validate([
            'task_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $plan = $this->findTask((int) $input['task_id'], $ctx);

        $task = new AddTaskAction(
            $plan,
            TaskData::fromMultiple($plan, $input),
        )->execute();

        return [
            'subtask' => $this->presentSubtask($task),
            'task_completion_pct' => $plan->completion_pct,
        ];
    }
}
