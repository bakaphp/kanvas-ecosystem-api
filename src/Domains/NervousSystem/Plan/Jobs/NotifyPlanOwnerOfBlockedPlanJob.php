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
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Throwable;

/**
 * Tell the person who owns a project-less plan that it is blocked.
 *
 * A plan in a project has a PM watching it. One created on its own has nobody, and the escalation
 * used to be gated on `project_id !== null` — so the blocks that most needed a human were the ones
 * nobody heard about.
 */
class NotifyPlanOwnerOfBlockedPlanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    private const int THROTTLE_MINUTES = 30;

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

        try {
            new PostPlanActivityMessageAction(
                plan: $plan,
                content: sprintf(
                    '%s⚠️ This plan is BLOCKED: %s Take it over, hand it to someone who can do it, or '
                    . 'grant the assignee what it is missing.',
                    $this->mentionFor($plan) ?? '',
                    trim((string) $plan->error_message) !== ''
                        ? trim((string) $plan->error_message)
                        : 'no reason was recorded — read the plan\'s comments for what the assignee said.',
                ),
                author: $plan->agent?->user ?? $owner,
                verb: 'plan-blocked-alert',
                extraPayload: ['alert' => 'plan_blocked', 'plan_id' => $plan->getId()],
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Mentioning is what actually notifies — a name in the text does nothing.
     */
    private function mentionFor(Plan $plan): ?string
    {
        $owner = $plan->user;

        if ($owner === null) {
            return null;
        }

        try {
            $displayname = trim($owner->getAppProfile($plan->app)->displayname);
        } catch (Throwable) {
            return null;
        }

        return $displayname !== '' ? '@' . $displayname . ' ' : null;
    }
}
