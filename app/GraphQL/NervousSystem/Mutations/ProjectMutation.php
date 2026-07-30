<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\Actions\DeleteProjectAction;
use Kanvas\NervousSystem\Project\Actions\IngestToProjectAction;
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
}
