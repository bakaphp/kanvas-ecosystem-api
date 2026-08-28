<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\Traits\AnnouncesPlanOutcome;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Tell the person who owns a project-less plan that it is blocked.
 *
 * A plan in a project has a PM watching it. One created on its own has nobody, and the escalation
 * used to be gated on `project_id !== null` — so the blocks that most needed a human were the ones
 * nobody heard about.
 */
class NotifyPlanOwnerOfBlockedPlanJob implements ShouldQueue
{
    use AnnouncesPlanOutcome;
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    private const int THROTTLE_MINUTES = 30;

    /** Enough to see the shape of the blockage without pasting the whole board into an alert. */
    private const int REASON_TASKS = 3;

    public function __construct(
        public readonly Plan $plan,
    ) {
    }

    public function handle(): void
    {
        $plan = $this->plan->refresh();

        // It can be unblocked within the 45s delay; a stale alert sends someone to look at work that
        // is already moving again.
        if ($plan->status !== PlanStatusEnum::BLOCKED->value) {
            return;
        }

        $owner = $plan->user;

        if ($owner === null) {
            return;
        }

        $this->overwriteAppService($plan->app);

        if (! Cache::add('ns:plan:' . $plan->getId() . ':blocked-alert', true, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        // No mention here — each destination addresses a different person; see the done alert.
        $body = sprintf(
            '⚠️ This plan is BLOCKED: %s Take it over, hand it to someone who can do it, or '
            . 'grant the assignee what it is missing.',
            $this->whyBlocked($plan),
        );

        $this->postToPlanBoard(
            $plan,
            $body,
            'plan-blocked-alert',
            'plan_blocked',
        );

        $this->alsoPostToOriginConversation($plan, $body, 'plan-blocked-alert');

        // The mention above can be dropped for an agent-classified user; this reaches the person
        // who asked regardless, and costs no model tokens.
        $this->notifyTheAsker($plan, 'Needs you', sprintf('%s is blocked: %s', $plan->title, $this->whyBlocked($plan)));
    }

    /**
     * Why it stopped, in the assignee's own words.
     *
     * `error_message` is only set when something wrote it, and the worker path does not — it records
     * the reason on the TASK it blocked. So a plan blocked by its own tasks reported "no reason was
     * recorded" while every task carried a precise one ("search_leads does not expose email fields"),
     * which turns an alert that could have been acted on into one that only says go and look.
     */
    private function whyBlocked(Plan $plan): string
    {
        $recorded = trim((string) $plan->error_message);

        if ($recorded !== '') {
            return $recorded;
        }

        $reasons = $plan->tasks()
            ->where('is_deleted', 0)
            ->where('status', TaskStatusEnum::BLOCKED->value)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->filter(fn (Task $task): bool => trim((string) $task->blocked_reason) !== '')
            ->take(self::REASON_TASKS)
            ->map(fn (Task $task): string => sprintf(
                '"%s" — %s',
                Str::limit($task->title, 60),
                Str::limit(trim((string) $task->blocked_reason), 200),
            ));

        return $reasons->isEmpty()
            ? 'no reason was recorded — read the plan\'s comments for what the assignee said.'
            : $reasons->implode(' ');
    }
}
