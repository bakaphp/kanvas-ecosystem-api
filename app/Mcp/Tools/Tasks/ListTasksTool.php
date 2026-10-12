<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Tasks;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\Concerns\PresentsProjectBoard;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Support\ProjectBoardColumns;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[IsReadOnly]
class ListTasksTool extends KanvasTool
{
    use PresentsProjectBoard;

    protected string $name = 'list_tasks';

    protected string $description = 'List the tasks of a project, optionally narrowed to one column or status. For the '
        . 'whole board grouped by column use get_project instead.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('The project whose tasks you want.')->required(),
            'board_column_key' => $schema->string()->description('Only tasks placed in this column.'),
            'status' => $schema->string()
                ->enum(array_column(PlanStatusEnum::cases(), 'value'))
                ->description('Only tasks in this status.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Page size, default 50.'),
            'page' => $schema->integer()->min(1)->description('Page number, default 1.'),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $project = $this->findProject((int) $request->get('project_id'), $ctx);
        $limit = min(100, max(1, (int) $request->get('limit', 50)));
        $page = max(1, (int) $request->get('page', 1));

        $query = $project->plans()
            ->when(
                $request->get('status'),
                fn ($q, string $status) => $q->where('status', PlanStatusEnum::fromAlias($status)->value),
            )
            ->orderByDesc('priority')
            ->orderBy('id');

        $columnKey = $request->get('board_column_key');

        if ($columnKey !== null) {
            new ProjectBoardColumns()->find($project, (string) $columnKey);

            // A task's column is derived when it has no key of its own, which SQL cannot express.
            $inColumn = $query->get()
                ->filter(fn (Plan $plan): bool => $this->columnKeyOf($project, $plan) === $columnKey)
                ->values();
            $total = $inColumn->count();
            $plans = $inColumn->forPage($page, $limit);
        } else {
            $total = (clone $query)->count();
            $plans = $query->forPage($page, $limit)->get();
        }

        return [
            'project_id' => $project->getId(),
            'total' => $total,
            'page' => $page,
            'tasks' => $plans
                ->map(fn (Plan $plan): array => [
                    ...$this->presentTask($plan),
                    'board_column_key' => $this->columnKeyOf($project, $plan),
                ])
                ->values()
                ->all(),
        ];
    }
}
