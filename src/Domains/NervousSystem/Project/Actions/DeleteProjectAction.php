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

    public function execute(): bool
    {
        $this->project->emitLedgerEvent('project.deleted', payload: [
            'title' => $this->project->title,
        ]);

        return (bool) $this->project->delete();
    }
}
