<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Tasks;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\Concerns\PresentsProjectBoard;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Override;

#[IsIdempotent]
class UpdateTaskTool extends KanvasTool
{
    use PresentsProjectBoard;

    private const array FIELDS = [
        'title',
        'description',
        'status',
        'priority',
        'deadline_at',
        'board_column_key',
    ];

    protected string $name = 'update_task';

    protected string $description = 'Change a task, or move it across the board. Send only the fields you want to '
        . 'change. To move a task to another column pass board_column_key — the task takes that column\'s '
        . 'status, so do not send status in the same call.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The task to change.')->required(),
            'title' => $schema->string()->description('New title.'),
            'description' => $schema->string()->description('New description.'),
            'board_column_key' => $schema->string()
                ->description('Move the task to this column, from list_board_columns.'),
            'status' => $schema->string()
                ->enum(array_column(PlanStatusEnum::cases(), 'value'))
                ->description('New status. Marking a task done also completes its open subtasks.'),
            'priority' => $schema->integer()->description('New priority; higher sorts first.'),
            'deadline_at' => $schema->string()->description('New deadline as an ISO-8601 date or datetime.'),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $request->validate([
            'task_id' => ['required', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'board_column_key' => ['sometimes', 'string'],
            'priority' => ['sometimes', 'integer'],
            'deadline_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $plan = $this->findTask((int) $request->get('task_id'), $ctx);

        $plan = new UpdatePlanAction(
            $plan,
            PlanData::forUpdate(
                $plan,
                $ctx->app,
                $ctx->company,
                $this->sent($request, self::FIELDS),
            ),
        )->execute();

        return ['task' => $this->presentTask($plan)];
    }
}
