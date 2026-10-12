<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Subtasks;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Plan\Actions\DeleteTaskAction;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Override;

#[IsDestructive]
class DeleteSubtaskTool extends KanvasTool
{
    protected string $name = 'delete_subtask';

    protected string $description = 'Remove a subtask from its task. Only call this when the person explicitly asked to '
        . 'delete it — to finish or skip a subtask, use update_subtask_status instead.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'subtask_id' => $schema->integer()->description('The subtask to delete.')->required(),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $task = $this->findSubtask((int) $request->get('subtask_id'), $ctx);

        return [
            'subtask_id' => $task->getId(),
            'task_id' => $task->plan_id,
            'deleted' => new DeleteTaskAction($task)->execute(),
        ];
    }
}
