<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\RemoveProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\UpdateProjectMemberRoleAction;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Models\ProjectMember;
use Kanvas\Users\Repositories\UsersRepository;

class ProjectMemberMutation
{
    use ResolvesActingContext;

    public function add(mixed $rootValue, array $request): ProjectMember
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        /** @var Project $project */
        $project = Project::getByIdFromCompanyApp((int) $request['project_id'], $ctx->company, $ctx->app);

        $role = ProjectMemberRoleEnum::from($input['role'] ?? ProjectMemberRoleEnum::CONTRIBUTOR->value);

        $memberAgent = isset($input['agent_id'])
            ? Agent::getByIdFromCompanyApp((int) $input['agent_id'], $ctx->company, $ctx->app)
            : null;

        $memberUser = ! $memberAgent && isset($input['users_id'])
            ? UsersRepository::getUserOfAppById((int) $input['users_id'], $ctx->app)
            : null;

        if ($memberAgent === null && $memberUser === null) {
            throw new ValidationException('Provide a users_id or an agent_id.');
        }

        return new AddProjectMemberAction(
            project: $project,
            role: $role,
            user: $memberUser,
            agent: $memberAgent,
        )->execute();
    }

    public function updateRole(mixed $rootValue, array $request): ProjectMember
    {
        $ctx = $this->actingContext();

        /** @var ProjectMember $member */
        $member = ProjectMember::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new UpdateProjectMemberRoleAction(
            $member,
            ProjectMemberRoleEnum::from((string) $request['role']),
        )->execute();
    }

    public function remove(mixed $rootValue, array $request): bool
    {
        $ctx = $this->actingContext();

        /** @var ProjectMember $member */
        $member = ProjectMember::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new RemoveProjectMemberAction($member)->execute();
    }
}
