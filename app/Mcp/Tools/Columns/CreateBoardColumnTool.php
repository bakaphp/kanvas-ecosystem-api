<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Columns;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Project\Actions\CreateProjectBoardColumnAction;
use Laravel\Mcp\Request;
use Override;

class CreateBoardColumnTool extends KanvasTool
{
    protected string $name = 'create_board_column';

    protected string $description = 'Add a column to the end of a project board. A column maps to one task status: a task '
        . 'moved into the column takes that status. Use reorder_board_columns afterwards to place it.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('The project whose board gets the column.')->required(),
            'name' => $schema->string()->description('Column title, up to 80 characters. Must be unique on the board.')
                ->required(),
            'task_status' => $schema->string()
                ->enum(array_column(PlanStatusEnum::cases(), 'value'))
                ->description('Status tasks take when moved into this column. Defaults to draft (not started).'),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $request->validate([
            'project_id' => ['required', 'integer'],
            'name' => ['required', 'string'],
        ]);

        $project = $this->findProject((int) $request->get('project_id'), $ctx);

        $column = new CreateProjectBoardColumnAction(
            project: $project,
            name: (string) $request->get('name'),
            planStatus: $request->get('task_status'),
        )->execute();

        return [
            'project_id' => $project->getId(),
            'column' => $column,
        ];
    }
}
