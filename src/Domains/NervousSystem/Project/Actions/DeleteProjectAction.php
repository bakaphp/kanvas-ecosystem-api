<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Kanvas\NervousSystem\Project\Models\Project;

class DeleteProjectAction
{
    public function __construct(
        protected readonly Project $project,
    ) {
    }

    /**
     * Soft-delete via SoftDeletesTrait so CascadeSoftDeletes fans out to the project's plans and
     * sub-projects. Emits the ledger event before deletion so the actor/entity are still resolvable.
     */
    public function execute(): bool
    {
        $this->project->emitLedgerEvent('project.deleted', payload: [
            'title' => $this->project->title,
        ]);

        return (bool) $this->project->delete();
    }
}
