<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Tasks;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\Concerns\PresentsProjectBoard;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Plan\Models\Task;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[IsReadOnly]
class GetTaskTool extends KanvasTool
{
    use PresentsProjectBoard;

    protected string $name = 'get_task';

    protected string $description = 'Read one task with its subtasks in order and the files attached to it.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The task to read.')->required(),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $plan = $this->findTask((int) $request->get('task_id'), $ctx);

        return [
            'task' => $this->presentTask($plan),
            'subtasks' => $plan->tasks()
                ->orderBy('sequence')
                ->orderBy('id')
                ->get()
                ->map(fn (Task $task): array => $this->presentSubtask($task))
                ->all(),
            'files' => $this->presentFiles($plan),
        ];
    }
}
