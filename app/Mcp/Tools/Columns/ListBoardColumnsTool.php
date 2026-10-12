<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Columns;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[IsReadOnly]
class ListBoardColumnsTool extends KanvasTool
{
    protected string $name = 'list_board_columns';

    protected string $description = 'List the columns of a project board in display order. Each column has a `key` — '
        . 'that key is what create_task and update_task take to place a task in a column.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('The project whose board you want.')->required(),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $project = $this->findProject((int) $request->get('project_id'), $ctx);

        return [
            'project_id' => $project->getId(),
            'columns' => $project->boardColumns(),
        ];
    }
}
