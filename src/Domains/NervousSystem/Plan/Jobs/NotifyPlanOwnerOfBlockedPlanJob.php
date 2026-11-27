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
use Kanvas\NervousSystem\Plan\Services\BlockOutcomeDeliveryService;

/** A deterministic block report when no project PM or delegated creator can answer the asker. */
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

    /** How long a coordinated alert waits between looks at an agent that is still answering. */
    private const int WAIT_FOR_CREATOR_SECONDS = 30;

    /** Enough 30-second waits to outlast the hour an agent is given to answer. */
    public int $tries = 125;

    public int $maxExceptions = 3;

    /**
     * @param string|null $blockOutcomeId the block cycle this alert is the fallback for, when the plan was
     *        handed off by an agent that answers first; null keeps the uncoordinated alert
     */
    public function __construct(
        public readonly Plan $plan,
        public readonly ?string $blockOutcomeId = null,
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

        if ($this->blockOutcomeId !== null) {
            $this->announceUnlessTheCreatorDoes($plan, $this->blockOutcomeId);

            return;
        }

        if (! Cache::add('ns:plan:' . $plan->getId() . ':blocked-alert', true, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        $this->announce($plan);
    }

    /**
     * The fallback for a plan an agent handed off. That agent answers first; this speaks only when it
     * did not, could not, or ran out of time, and never on top of it.
     */
    private function announceUnlessTheCreatorDoes(Plan $plan, string $cycle): void
    {
        $delivery = new BlockOutcomeDeliveryService($plan);

        if (! $delivery->isAwaitingAnAnswer($cycle)) {
            return;
        }

        // Back on the queue before any claim is taken, so the wait costs the fallback nothing.
        if ($delivery->creatorIsPending($cycle)) {
            $this->release(self::WAIT_FOR_CREATOR_SECONDS);

            return;
        }

        $delivery->claimDelivery($cycle, asCreator: false, deliver: fn (): bool => $this->announce($plan));
    }

    private function announce(Plan $plan): bool
    {
        $why = $this->whyBlocked($plan);

        // No mention here — each destination addresses a different person; see the done alert.
        $body = sprintf(
            '⚠️ This plan is BLOCKED: %s Take it over, hand it to someone who can do it, or '
            . 'grant the assignee what it is missing.',
            $why,
        );

        $this->postToPlanBoard(
            $plan,
            $body,
            'plan-blocked-alert',
            'plan_blocked',
        );

        $posted = $this->alsoPostToOriginConversation($plan, $body, 'plan-blocked-alert');

        // The mention above can be dropped for an agent-classified user; this reaches the person
        // who asked regardless, and costs no model tokens.
        $this->notifyTheAsker($plan, 'Needs you', sprintf('%s is blocked: %s', $plan->title, $why));

        return $posted !== null;
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
