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

        // Membership identity is the MEMBER ENTITY, not its user: an agent is keyed by agent_id, a
        // human by users_id. Two distinct agents can share one Kanvas user, so matching on users_id
        // would collapse them (a second add updates the first instead of creating a new member).
        $match = [
            'apps_id' => $this->project->apps_id,
            'companies_id' => $this->project->companies_id,
            'project_id' => $this->project->getId(),
        ];
        if ($this->agent !== null) {
            $match['agent_id'] = $agentId;
        } else {
            $match['users_id'] = $usersId;
            $match['agent_id'] = null;
        }

        return DB::connection('intelligence')->transaction(function () use ($match, $memberType, $usersId, $agentId): ProjectMember {
            // withTrashed so a re-add finds and restores a previously removed row rather than
            // colliding with the unique index it left behind.
            /** @var ProjectMember $member */
            $member = ProjectMember::query()->withTrashed()->updateOrCreate(
                $match,
                [
                    'member_type' => $memberType->value,
                    'users_id' => $usersId,
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
