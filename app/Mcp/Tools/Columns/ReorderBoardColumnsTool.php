<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Columns;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Project\Actions\ReorderProjectBoardColumnsAction;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Override;

#[IsIdempotent]
class ReorderBoardColumnsTool extends KanvasTool
{
    protected string $name = 'reorder_board_columns';

    protected string $description = 'Set the left-to-right order of a project board. Pass every existing column key '
        . 'exactly once, in the order you want.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('The project that owns the board.')->required(),
            'keys' => $schema->array()
                ->items($schema->string())
                ->description('All column keys of the board, in the new order.')
                ->required(),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $request->validate([
            'project_id' => ['required', 'integer'],
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['string'],
        ]);

        $project = $this->findProject((int) $request->get('project_id'), $ctx);

        $columns = new ReorderProjectBoardColumnsAction(
            project: $project,
            keys: array_map('strval', (array) $request->get('keys')),
        )->execute();

        return [
            'project_id' => $project->getId(),
            'columns' => $columns,
        ];
    }
}
