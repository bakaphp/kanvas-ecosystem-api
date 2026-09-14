<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Support\MentionHandle;
use Kanvas\NervousSystem\Project\Actions\PostProjectMessageAction;
use Kanvas\NervousSystem\Project\Models\Project;

class NotifyProjectOwnerOfBlockedPlanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    private const int THROTTLE_MINUTES = 60;

    public function __construct(
        public readonly Plan $plan,
    ) {
    }

    public function handle(): void
    {
        if ($this->plan->project_id === null) {
            return;
        }

        $project = Project::query()->where('id', $this->plan->project_id)->notDeleted()->first();
        if ($project === null) {
            return;
        }

        $this->overwriteAppService($project->app);

        // One alert per PROJECT per window — a burst of blocked plans yields a single digest, not one
        // ping per plan, and it won't re-alert for an hour.
        if (! Cache::add('ns:project:' . (int) $project->getId() . ':blocked-alert', true, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        $blocked = Plan::query()
            ->where('project_id', $project->getId())
            ->where('status', PlanStatusEnum::BLOCKED->value)
            ->where('is_deleted', 0)
            ->orderByDesc('id')
            ->get();

        if ($blocked->isEmpty()) {
            return;
        }

        $owner = $project->owner;
        if ($owner === null) {
            return;
        }

        // Alert is FROM the PM (the orchestrator), addressed to the owner.
        $author = $project->pmAgent?->user ?? $owner;
        $handle = $this->ownerHandle($project);
        $mention = $handle !== null ? $handle . ' ' : '';

        $list = $blocked
            ->map(fn (Plan $plan): string => sprintf('"%s" (#%d)', $plan->title, $plan->getId()))
            ->implode(', ');

        new PostProjectMessageAction(
            project: $project,
            verb: 'plan-blocked-alert',
            content: sprintf(
                "%s\u{26A0}\u{FE0F} %d plan(s) BLOCKED and need attention: %s. Reassign each to a capable "
                . 'member/agent, or take them over.',
                $mention,
                $blocked->count(),
                $list,
            ),
            author: $author,
            fromIa: true,
            extraPayload: ['alert' => 'plan_blocked', 'blocked_count' => $blocked->count()],
        )->execute();
    }

    private function ownerHandle(Project $project): ?string
    {
        $owner = $project->owner;
        $handle = MentionHandle::forUser($owner, $project->app);

        return $handle !== null ? '@' . $handle : null;
    }
}
