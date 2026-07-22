<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\NervousSystem\Project\Actions\CreateWorkspaceAction;
use Kanvas\NervousSystem\Project\Actions\DeleteWorkspaceAction;
use Kanvas\NervousSystem\Project\Actions\UpdateWorkspaceAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Workspace as WorkspaceData;
use Kanvas\NervousSystem\Project\Models\Workspace;

class WorkspaceMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): Workspace
    {
        $ctx = $this->actingContext();

        return new CreateWorkspaceAction(
            WorkspaceData::from(
                $ctx->app,
                $ctx->user,
                $ctx->company,
                $request['input'],
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Workspace
    {
        $ctx = $this->actingContext();

        /** @var Workspace $workspace */
        $workspace = Workspace::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new UpdateWorkspaceAction(
            $workspace,
            WorkspaceData::forUpdate(
                $workspace,
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

        /** @var Workspace $workspace */
        $workspace = Workspace::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new DeleteWorkspaceAction($workspace)->execute();
    }
}
