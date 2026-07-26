<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;

class CreateProjectAction
{
    public function __construct(
        protected readonly ProjectData $data,
    ) {
    }

    public function execute(): Project
    {
        $this->assertValidHeartbeatInterval();

        // Register Project as a SystemModule so the shared tag mutations work (mirrors Plan).
        new CreateInCurrentAppAction($this->data->app)->execute(Project::class);

        // pmAgent is guaranteed by the DTO (non-nullable) — a project can't exist without its agent.
        $pmAgent = $this->data->pmAgent;

        $workspace = $this->data->workspace ?? new EnsureDefaultWorkspaceAction(
            app: $this->data->app,
            company: $this->data->company,
            owner: $this->data->owner,
        )->execute();

        return DB::connection('intelligence')->transaction(function () use ($pmAgent, $workspace): Project {
            $project = new Project();
            $project->apps_id = $this->data->app->getId();
            $project->companies_id = $this->data->company->getId();
            $project->users_id = $this->data->owner->getId();
            $project->workspace_id = $workspace->getId();
            $project->agent_id = $pmAgent->getId();
            $project->swarm_id = $this->data->swarm?->getId();
            $project->parent_project_id = $this->data->parentProject?->id;
            $project->title = $this->data->title;
            $project->slug = Str::slug($this->data->title) . '-' . Str::random(6);
            $project->objective = $this->data->objective;
            $project->description = $this->data->description;
            $project->status = $this->data->status->value;
            $project->priority = $this->data->priority;
            $project->deadline_at = $this->data->deadlineAt;
            $project->completion_pct = 0;
            $project->heartbeat_interval_minutes = $this->data->heartbeatIntervalMinutes;
            $project->saveOrFail();

            if ($this->data->files !== []) {
                $project->addMultipleFilesFromUrl($this->data->files);
            }

            $project->emitLedgerEvent('project.created', payload: [
                'title' => $project->title,
                'status' => $project->status,
                'workspace_id' => $project->workspace_id,
                'file_count' => count($this->data->files),
            ]);

            return $project;
        });
    }

    private function assertValidHeartbeatInterval(): void
    {
        if (! in_array($this->data->heartbeatIntervalMinutes, Project::ALLOWED_HEARTBEAT_INTERVALS, true)) {
            throw new ValidationException(sprintf(
                'heartbeat_interval_minutes must be one of %s.',
                implode(', ', Project::ALLOWED_HEARTBEAT_INTERVALS),
            ));
        }
    }
}
