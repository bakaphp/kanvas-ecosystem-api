<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Queries;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Support\ProjectBoardColumns;

/**
 * @phpstan-import-type BoardColumn from ProjectBoardColumns
 */
class ProjectBoardColumnQuery
{
    use ResolvesActingContext;

    /**
     * @return array<int, BoardColumn>
     */
    public function list(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        /** @var Project $project */
        $project = Project::getByIdFromCompanyApp((int) $request['project_id'], $ctx->company, $ctx->app);

        return $project->boardColumns();
    }
}
