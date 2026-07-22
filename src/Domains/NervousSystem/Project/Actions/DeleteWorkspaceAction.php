<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Kanvas\NervousSystem\Project\Models\Workspace;

class DeleteWorkspaceAction
{
    public function __construct(
        protected readonly Workspace $workspace,
    ) {
    }

    /**
     * Soft-delete via SoftDeletesTrait so CascadeSoftDeletes fans out to the workspace's projects.
     */
    public function execute(): bool
    {
        return (bool) $this->workspace->delete();
    }
}
