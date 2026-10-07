<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Notifications\ProjectInactivePlansNotification;
use Kanvas\NervousSystem\Project\Support\InactivePlanNudge;

class NotifyProjectInactivePlansAction
{
    /**
     * @param list<InactivePlanNudge> $nudges
     */
    public function __construct(
        private readonly Project $project,
        private readonly array $nudges,
        private readonly int $thresholdHours,
    ) {
    }

    public function execute(): bool
    {
        $owner = $this->project->user;
        if ($owner === null || $this->nudges === []) {
            return false;
        }

        $owner->notify(new ProjectInactivePlansNotification($this->project, $this->nudges, $this->thresholdHours));

        return true;
    }
}
