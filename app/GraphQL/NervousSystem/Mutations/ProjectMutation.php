<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\Actions\DeleteProjectAction;
use Kanvas\NervousSystem\Project\Actions\UpdateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;

class ProjectMutation
{
    public function create(mixed $rootValue, array $request): Project
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                $request['input'],
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Project
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        /** @var Companies $company */
        $company = $user->getCurrentCompany();

        /** @var Project $project */
        $project = Project::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateProjectAction(
            $project,
            ProjectData::forUpdate(
                $project,
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

        /** @var Project $project */
        $project = Project::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new DeleteProjectAction($project)->execute();
    }
}
