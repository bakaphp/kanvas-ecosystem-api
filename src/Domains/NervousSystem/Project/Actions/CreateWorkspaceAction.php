<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Project\DataTransferObject\Workspace as WorkspaceData;
use Kanvas\NervousSystem\Project\Models\Workspace;

class CreateWorkspaceAction
{
    public function __construct(
        protected readonly WorkspaceData $data,
    ) {
    }

    public function execute(): Workspace
    {
        return DB::connection('intelligence')->transaction(function (): Workspace {
            $workspace = new Workspace();
            $workspace->apps_id = $this->data->app->getId();
            $workspace->companies_id = $this->data->company->getId();
            $workspace->users_id = $this->data->owner->getId();
            $workspace->agent_id = $this->data->oversightAgent?->getId();
            $workspace->name = $this->data->name;
            $workspace->description = $this->data->description;
            $workspace->status = $this->data->status->value;
            $workspace->saveOrFail();

            return $workspace;
        });
    }
}
