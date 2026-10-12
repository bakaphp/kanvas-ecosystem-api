<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Projects;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\Concerns\PresentsProjectBoard;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[IsReadOnly]
class GetProjectTool extends KanvasTool
{
    use PresentsProjectBoard;

    private const int MAX_TASKS = 200;

    protected string $name = 'get_project';

    protected string $description = 'Read one AI project as a board: its details, its columns in order, and the tasks '
        . 'sitting in each column. Call get_task for the subtasks and files of a single task.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('The project to read.')->required(),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $project = $this->findProject((int) $request->get('project_id'), $ctx);
        $columns = [];
        foreach ($project->boardColumns() as $column) {
            $columns[$column['key']] = $column + ['tasks' => []];
        }

        $unplaced = [];
        $plans = $project->plans()->orderByDesc('priority')->orderBy('id')->limit(self::MAX_TASKS)->get();

        foreach ($plans as $plan) {
            /** @var Plan $plan */
            $key = $this->columnKeyOf($project, $plan);

            if ($key !== null && isset($columns[$key])) {
                $columns[$key]['tasks'][] = $this->presentTask($plan);

                continue;
            }

            $unplaced[] = $this->presentTask($plan);
        }

        return [
            'project' => $this->presentProject($project),
            'columns' => array_values($columns),
            'tasks_without_column' => $unplaced,
        ];
    }
}
