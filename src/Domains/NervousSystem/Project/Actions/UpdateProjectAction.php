<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Models\Project;

class UpdateProjectAction
{
    public function __construct(
        protected readonly Project $project,
        protected readonly ProjectData $data,
    ) {
    }

    public function execute(): Project
    {
        Project::assertValidHeartbeatSettings(
            $this->data->heartbeatIntervalMinutes,
            $this->data->heartbeatMaxBackoffMinutes,
        );

        return DB::connection('intelligence')->transaction(function (): Project {
            $this->project->agent_id = $this->data->pmAgent->getId();
            $this->project->workspace_id = $this->data->workspace?->getId();
            $this->project->swarm_id = $this->data->swarm?->getId();
            $this->project->title = $this->data->title;
            $this->project->objective = $this->data->objective;
            $this->project->description = $this->data->description;
            $this->project->status = $this->data->status->value;
            $this->project->priority = $this->data->priority;
            $this->project->deadline_at = $this->data->deadlineAt;
            $this->project->heartbeat_interval_minutes = $this->data->heartbeatIntervalMinutes;
            $this->project->heartbeat_max_backoff_minutes = $this->data->heartbeatMaxBackoffMinutes;
            $this->project->saveOrFail();

            if ($this->data->files !== []) {
                $this->project->addMultipleFilesFromUrl($this->data->files);
            }

            $this->project->emitLedgerEvent('project.updated', payload: [
                'title' => $this->project->title,
                'status' => $this->project->status,
            ]);

            return $this->project;
        });
    }
}
