<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Project\DataTransferObject\Workspace as WorkspaceData;
use Kanvas\NervousSystem\Project\Models\Workspace;
use Kanvas\Users\Models\Users;

/**
 * Resolve the company's default "General" workspace, creating it once if missing — so a project
 * created without an explicit workspace still lands in a portfolio the humans can see.
 */
class EnsureDefaultWorkspaceAction
{
    private const string DEFAULT_NAME = 'General';

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $owner,
    ) {
    }

    public function execute(): Workspace
    {
        $existing = Workspace::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('slug', 'general')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return new CreateWorkspaceAction(
            new WorkspaceData(
                app: $this->app,
                company: $this->company,
                owner: $this->owner,
                name: self::DEFAULT_NAME,
            ),
        )->execute();
    }
}
