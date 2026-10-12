<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Projects;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\Concerns\PresentsProjectBoard;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Project\Enums\ProjectStatusEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[IsReadOnly]
class ListProjectsTool extends KanvasTool
{
    use PresentsProjectBoard;

    protected string $name = 'list_projects';

    protected string $description = 'List the AI projects of the current company, newest first. Use it to find a '
        . 'project id before reading or changing a board.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(array_column(ProjectStatusEnum::cases(), 'value'))
                ->description('Only projects in this status.'),
            'search' => $schema->string()->description('Only projects whose title contains this text.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Page size, default 25.'),
            'page' => $schema->integer()->min(1)->description('Page number, default 1.'),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $limit = min(100, max(1, (int) $request->get('limit', 25)));
        $page = max(1, (int) $request->get('page', 1));

        $query = Project::query()
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->notDeleted()
            ->when(
                $request->get('status'),
                fn ($q, string $status) => $q->where('status', ProjectStatusEnum::fromAlias($status)->value),
            )
            ->when(
                $request->get('search'),
                fn ($q, string $search) => $q->where('title', 'like', '%' . $search . '%'),
            );

        $total = (clone $query)->count();
        $projects = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return [
            'total' => $total,
            'page' => $page,
            'projects' => $projects->map(fn (Project $project): array => $this->presentProject($project))->all(),
        ];
    }
}
