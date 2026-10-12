<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Tasks;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Plan\Actions\DeletePlanAction;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Override;

#[IsDestructive]
class DeleteTaskTool extends KanvasTool
{
    protected string $name = 'delete_task';

    protected string $description = 'Delete a task and all of its subtasks from the board. Only call this when the '
        . 'person explicitly asked to delete it — to finish a task, update its status instead.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The task to delete.')->required(),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $plan = $this->findTask((int) $request->get('task_id'), $ctx);

        return [
            'task_id' => $plan->getId(),
            'deleted' => new DeletePlanAction($plan)->execute(),
        ];
    }
}
