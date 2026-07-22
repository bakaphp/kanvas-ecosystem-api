<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Project\Actions\CreateWorkspaceAction;
use Kanvas\NervousSystem\Project\Actions\DeleteWorkspaceAction;
use Kanvas\NervousSystem\Project\Actions\UpdateWorkspaceAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Workspace as WorkspaceData;
use Kanvas\NervousSystem\Project\Models\Workspace;
use Kanvas\Users\Models\Users;

class WorkspaceMutation
{
    public function create(mixed $rootValue, array $request): Workspace
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateWorkspaceAction(
            WorkspaceData::from(
                $app,
                $user,
                $company,
                $request['input'],
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Workspace
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        /** @var Companies $company */
        $company = $user->getCurrentCompany();

        /** @var Workspace $workspace */
        $workspace = Workspace::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateWorkspaceAction(
            $workspace,
            WorkspaceData::forUpdate(
                $workspace,
                $app,
                $company,
                $user,
                $request['input'],
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        /** @var Companies $company */
        $company = $user->getCurrentCompany();

        /** @var Workspace $workspace */
        $workspace = Workspace::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new DeleteWorkspaceAction($workspace)->execute();
    }
}
