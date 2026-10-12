<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Subtasks;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\Concerns\PresentsProjectBoard;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Override;

#[IsIdempotent]
class UpdateSubtaskStatusTool extends KanvasTool
{
    use PresentsProjectBoard;

    protected string $name = 'update_subtask_status';

    protected string $description = 'Change the status of a subtask — start it, complete it, skip it or block it. Pass '
        . 'blocked_reason when blocking, and summary to record what was done when completing.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'subtask_id' => $schema->integer()->description('The subtask to change.')->required(),
            'status' => $schema->string()
                ->enum(array_column(TaskStatusEnum::cases(), 'value'))
                ->description('New status.')
                ->required(),
            'blocked_reason' => $schema->string()->description('Why it is blocked. Only with status blocked.'),
            'summary' => $schema->string()->description('Short note on the outcome, stored as the subtask result.'),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $input = $request->validate([
            'subtask_id' => ['required', 'integer'],
            'status' => ['required', 'string'],
            'blocked_reason' => ['nullable', 'string'],
            'summary' => ['nullable', 'string'],
        ]);

        $task = $this->findSubtask((int) $input['subtask_id'], $ctx);

        $task = new UpdateTaskStatusAction(
            task: $task,
            newStatus: TaskStatusEnum::fromAlias($input['status']),
            result: isset($input['summary']) ? ['result' => $input['summary']] : null,
            blockedReason: $input['blocked_reason'] ?? null,
        )->execute();

        return ['subtask' => $this->presentSubtask($task)];
    }
}
