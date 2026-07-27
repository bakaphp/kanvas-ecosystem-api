<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Kanvas\NervousSystem\Project\Models\ProjectMember;

class RemoveProjectMemberAction
{
    public function __construct(
        private readonly ProjectMember $member,
    ) {
    }

    public function execute(): bool
    {
        return $this->member->softDelete();
    }
}
