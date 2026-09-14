<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\NervousSystem\Project\Actions\CreateProjectBoardColumnAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\Actions\DeleteProjectAction;
use Kanvas\NervousSystem\Project\Actions\IngestToProjectAction;
use Kanvas\NervousSystem\Project\Actions\RenameProjectBoardColumnAction;
use Kanvas\NervousSystem\Project\Actions\ReorderProjectBoardColumnsAction;
use Kanvas\NervousSystem\Project\Actions\UpdateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Models\Message;

class ProjectMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): Project
    {
        $ctx = $this->actingContext();

        return new CreateProjectAction(
            ProjectData::from(
                $ctx->app,
                $ctx->user,
                $ctx->company,
                $request['input'],
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Project
    {
        $ctx = $this->actingContext();

        /** @var Project $project */
        $project = Project::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new UpdateProjectAction(
            $project,
            ProjectData::forUpdate(
                $project,
                $ctx->app,
                $ctx->company,
                $ctx->user,
                $request['input'],
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $ctx = $this->actingContext();

        /** @var Project $project */
        $project = Project::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new DeleteProjectAction($project)->execute();
    }

    public function attachMessage(mixed $rootValue, array $request): Message
    {
        $ctx = $this->actingContext();

        /** @var Project $project */
        $project = Project::getByIdFromCompanyApp((int) $request['project_id'], $ctx->company, $ctx->app);

        return new IngestToProjectAction(
            project: $project,
            type: ProjectIngestTypeEnum::from($request['type'] ?? ProjectIngestTypeEnum::MENTION->value),
            content: (string) $request['content'],
            author: $ctx->user,
        )->execute();
    }

    /**
     * @return array{key: string, name: string, position: int, plan_status: string, legacy_statuses: array<int, string>}
     */
    public function createBoardColumn(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        /** @var Project $project */
        $project = Project::getByIdFromCompanyApp((int) $request['project_id'], $ctx->company, $ctx->app);

        return new CreateProjectBoardColumnAction(
            project: $project,
            name: (string) $request['input']['name'],
            planStatus: $request['input']['plan_status'] ?? null,
        )->execute();
    }

    /**
     * @return array{key: string, name: string, position: int, plan_status: string, legacy_statuses: array<int, string>}
     */
    public function renameBoardColumn(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        /** @var Project $project */
        $project = Project::getByIdFromCompanyApp((int) $request['project_id'], $ctx->company, $ctx->app);

        return new RenameProjectBoardColumnAction(
            project: $project,
            key: (string) $request['key'],
            name: (string) $request['name'],
        )->execute();
    }

    /**
     * @return array<int, array{key: string, name: string, position: int, plan_status: string, legacy_statuses: array<int, string>}>
     */
    public function reorderBoardColumns(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        /** @var Project $project */
        $project = Project::getByIdFromCompanyApp((int) $request['project_id'], $ctx->company, $ctx->app);

        return new ReorderProjectBoardColumnsAction(
            project: $project,
            keys: array_map('strval', $request['keys']),
        )->execute();
    }
}
