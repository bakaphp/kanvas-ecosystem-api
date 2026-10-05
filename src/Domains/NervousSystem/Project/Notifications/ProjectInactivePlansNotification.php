<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Notifications;

use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Support\InactivePlanNudge;
use Kanvas\Notifications\Concerns\PushesTitleAndMessageFromData;
use Kanvas\Notifications\Notification;
use Override;

/**
 * One mail + push per project per sweep listing every plan the inactivity nudge acted on — the owner
 * hears about a project's stale plans once, not once per plan.
 */
class ProjectInactivePlansNotification extends Notification
{
    use PushesTitleAndMessageFromData;

    private const string VIEW = 'emails.nervous-system.project-inactive-plans';

    /**
     * @param list<InactivePlanNudge> $nudges
     */
    public function __construct(
        Project $project,
        array $nudges,
        int $thresholdHours,
    ) {
        $fromUser = $project->pmUser();
        $plans = array_map(fn (InactivePlanNudge $nudge): array => $nudge->toArray(), $nudges);
        $count = count($plans);
        $noun = Str::plural('plan', $count);
        $title = sprintf('%s: %d inactive %s', $project->title, $count, $noun);

        $data = [
            'app' => $project->app,
            'company' => $project->company,
            'fromUser' => $fromUser,
            'title' => $title,
            'message' => sprintf(
                '%d %s in "%s" had no activity in over %dh.',
                $count,
                $noun,
                $project->title,
                $thresholdHours,
            ),
            'project_url' => $project->adminUrl(),
            'inactive_hours' => $thresholdHours,
            'plans' => $plans,
            'metadata' => [
                'project_id' => $project->getId(),
                'project_uuid' => $project->uuid,
                'change_type' => 'stale',
                'inactive_hours' => $thresholdHours,
                'plan_ids' => array_column($plans, 'id'),
            ],
        ];

        parent::__construct($project, $data);
        $this->setType('blank');
        $this->setSubject($title);
        $this->setData($data);

        if ($fromUser !== null) {
            $this->setFromUser($fromUser);
        }

        $this->channels = ['mail', 'push'];
    }

    #[Override]
    public function getEmailContent(): string
    {
        return View::make(self::VIEW, $this->getData())->render();
    }
}
