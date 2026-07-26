<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Models\ProjectMember;

class UpdateProjectMemberRoleAction
{
    public function __construct(
        private readonly ProjectMember $member,
        private readonly ProjectMemberRoleEnum $role,
    ) {
    }

    public function execute(): ProjectMember
    {
        $this->member->role = $this->role->value;
        $this->member->saveOrFail();

        return $this->member;
    }
}
