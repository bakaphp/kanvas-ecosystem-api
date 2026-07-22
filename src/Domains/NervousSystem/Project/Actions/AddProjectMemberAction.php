<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberTypeEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Models\ProjectMember;
use Kanvas\Users\Models\Users;

class AddProjectMemberAction
{
    public function __construct(
        private readonly Project $project,
        private readonly ProjectMemberRoleEnum $role,
        private readonly ?Users $user = null,
        private readonly ?Agent $agent = null,
    ) {
    }

    public function execute(): ProjectMember
    {
        if (($this->user === null) === ($this->agent === null)) {
            throw new ValidationException('Provide exactly one of user or agent.');
        }

        $memberType = $this->agent !== null ? ProjectMemberTypeEnum::AGENT : ProjectMemberTypeEnum::USER;
        $usersId = $this->agent !== null ? (int) $this->agent->user_id : $this->user->getId();
        $agentId = $this->agent?->getId();

        return DB::connection('intelligence')->transaction(function () use ($memberType, $usersId, $agentId): ProjectMember {
            // withTrashed so a re-add finds and restores a previously removed row rather than
            // colliding with the unique (project_id, users_id) index it left behind.
            /** @var ProjectMember $member */
            $member = ProjectMember::query()->withTrashed()->updateOrCreate(
                [
                    'apps_id' => $this->project->apps_id,
                    'companies_id' => $this->project->companies_id,
                    'project_id' => $this->project->getId(),
                    'users_id' => $usersId,
                ],
                [
                    'member_type' => $memberType->value,
                    'agent_id' => $agentId,
                    'role' => $this->role->value,
                    'is_active' => true,
                    'is_deleted' => false,
                ],
            );

            return $member;
        });
    }
}
