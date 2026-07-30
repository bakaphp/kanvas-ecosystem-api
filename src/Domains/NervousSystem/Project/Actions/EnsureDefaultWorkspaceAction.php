<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
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
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
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
