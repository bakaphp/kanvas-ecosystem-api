<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
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
        $this->assertValidHeartbeatInterval();

        return DB::connection('intelligence')->transaction(function (): Project {
            $this->project->workspace_id = $this->data->workspace?->getId();
            $this->project->swarm_id = $this->data->swarm?->getId();
            $this->project->title = $this->data->title;
            $this->project->objective = $this->data->objective;
            $this->project->description = $this->data->description;
            $this->project->status = $this->data->status->value;
            $this->project->priority = $this->data->priority;
            $this->project->deadline_at = $this->data->deadlineAt;
            $this->project->heartbeat_interval_minutes = $this->data->heartbeatIntervalMinutes;
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
