<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Columns;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Project\Actions\RenameProjectBoardColumnAction;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Override;

#[IsIdempotent]
class RenameBoardColumnTool extends KanvasTool
{
    protected string $name = 'rename_board_column';

    protected string $description = 'Rename a column of a project board. The column `key` does not change, so tasks stay '
        . 'where they are.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('The project that owns the board.')->required(),
            'key' => $schema->string()->description('The column key, from list_board_columns.')->required(),
            'name' => $schema->string()->description('New column title, up to 80 characters.')->required(),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $request->validate([
            'project_id' => ['required', 'integer'],
            'key' => ['required', 'string'],
            'name' => ['required', 'string'],
        ]);

        $project = $this->findProject((int) $request->get('project_id'), $ctx);

        $column = new RenameProjectBoardColumnAction(
            project: $project,
            key: (string) $request->get('key'),
            name: (string) $request->get('name'),
        )->execute();

        return [
            'project_id' => $project->getId(),
            'column' => $column,
        ];
    }
}
