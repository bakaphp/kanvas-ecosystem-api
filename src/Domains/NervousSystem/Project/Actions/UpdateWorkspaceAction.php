<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Project\DataTransferObject\Workspace as WorkspaceData;
use Kanvas\NervousSystem\Project\Models\Workspace;

class UpdateWorkspaceAction
{
    public function __construct(
        protected readonly Workspace $workspace,
        protected readonly WorkspaceData $data,
    ) {
    }

    public function execute(): Workspace
    {
        return DB::connection('intelligence')->transaction(function (): Workspace {
            $this->workspace->agent_id = $this->data->oversightAgent?->getId();
            $this->workspace->name = $this->data->name;
            $this->workspace->description = $this->data->description;
            $this->workspace->status = $this->data->status->value;
            $this->workspace->saveOrFail();

            return $this->workspace;
        });
    }
}
