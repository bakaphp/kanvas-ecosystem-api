<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Notifications\PlanProgressNotification;
use Kanvas\NervousSystem\Project\Jobs\WakeWorkerForPlanJob;
use Kanvas\NervousSystem\Project\Services\StalePlanNudgeService;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Acts on ONE plan that's gone silent past the threshold — pings the human owner, re-wakes (then
 * escalates) a stalled agent, or nudges the owner of unassigned work; the project's human PM is always
 * notified. Anti-spam is deliberate (we've been bitten before): a plan.staleness.detected event caps it
 * to one nudge per window, and a plan.staleness.rewoke event marks a re-wake so a dead agent isn't
 * re-woken forever — the next window escalates to a human instead.
 */
class NudgeInactivePlanAction
{
    public const string RESULT_SKIPPED = 'skipped_recently_nudged';
    public const string RESULT_NO_PROJECT = 'no_project';
    public const string RESULT_PINGED_HUMAN = 'pinged_human';
    public const string RESULT_REWOKE_AGENT = 'rewoke_agent';
    public const string RESULT_ESCALATED_AGENT = 'escalated_agent';
    public const string RESULT_PINGED_OWNER = 'pinged_owner_unassigned';

    private const string EVENT_DETECTED = 'plan.staleness.detected';
    private const string EVENT_REWOKE = 'plan.staleness.rewoke';

    public function __construct(
        private readonly Plan $plan,
        private readonly int $thresholdHours,
        private readonly bool $force = false,
    ) {
    }

    public function execute(): string
    {
        // One nudge per plan per window — the guard that stops the daily sweep re-pinging forever.
        // --force (manual/testing) bypasses it.
        if (! $this->force && $this->nudgedWithinWindow()) {
            return self::RESULT_SKIPPED;
        }

        $project = $this->plan->project;
        if ($project === null) {
            return self::RESULT_NO_PROJECT;
        }

        $owner = $project->user;
        $pmUser = $project->pmAgent?->user ?? $owner;

        $result = $this->act($owner, $pmUser);
        $this->notifyOwner($owner, $result);

        $this->plan->emitLedgerEvent(self::EVENT_DETECTED, payload: [
            'action' => $result,
            'inactive_hours' => $this->thresholdHours,
        ]);

        return $result;
    }

    private function act(?Users $owner, ?Users $pmUser): string
    {
        $human = $this->plan->assigned_users_id !== null ? $this->plan->assignedUser : null;
        if ($human !== null) {
            $this->postComment(
                $pmUser,
                sprintf(
                    '%s this plan has had no activity in over %dh. What\'s the current status — is '
                    . 'anything blocking you?',
                    $this->handleFor($human),
                    $this->thresholdHours,
                ),
            );

            return self::RESULT_PINGED_HUMAN;
        }

        if ($this->plan->agent_id !== null) {
            return $this->handleAgentAssigned($owner, $pmUser);
        }

        return $this->pingOwner(
            $pmUser,
            $owner,
            sprintf(
                'this plan is unassigned and has had no activity in over %dh. Can you assign it or say '
                . 'what it\'s waiting on?',
                $this->thresholdHours,
            ),
        );
    }

    private function handleAgentAssigned(?Users $owner, ?Users $pmUser): string
    {
        // A re-wake produces no channel message, so if one already fired since the last real activity
        // and the plan is STILL silent, the agent is dead — escalate to a human instead of re-waking it.
        $rewoke = $this->lastEvent(self::EVENT_REWOKE);
        $activity = new StalePlanNudgeService()->lastActivityAt($this->plan);

        if ($rewoke !== null && $rewoke->occurred_at->greaterThan($activity)) {
            return $this->pingOwner(
                $pmUser,
                $owner,
                sprintf(
                    'the assigned agent has been silent on this plan for over %dh even after a re-wake. '
                    . 'It looks stuck — please reassign it or bring in a capable agent/human.',
                    $this->thresholdHours,
                ),
            );
        }

        WakeWorkerForPlanJob::dispatch($this->plan);
        $this->plan->emitLedgerEvent(self::EVENT_REWOKE, payload: [
            'agent_id' => $this->plan->agent_id,
            'inactive_hours' => $this->thresholdHours,
        ]);

        return self::RESULT_REWOKE_AGENT;
    }

    private function pingOwner(?Users $pmUser, ?Users $owner, string $ask): string
    {
        if ($owner === null) {
            return self::RESULT_PINGED_OWNER;
        }

        $this->postComment(
            $pmUser,
            sprintf('%s the plan "%s" %s', $this->handleFor($owner), $this->plan->title, $ask),
        );

        return $this->plan->agent_id !== null ? self::RESULT_ESCALATED_AGENT : self::RESULT_PINGED_OWNER;
    }

    /**
     * The human PM (project owner) always gets a heads-up — except when the nudge already @mentioned
     * them in the plan comment (they'd get the mention notification too).
     */
    private function notifyOwner(?Users $owner, string $result): void
    {
        if ($owner === null) {
            return;
        }

        $alreadyMentioned = match ($result) {
            self::RESULT_PINGED_HUMAN => (int) $this->plan->assigned_users_id,
            self::RESULT_PINGED_OWNER, self::RESULT_ESCALATED_AGENT => $owner->getId(),
            default => null,
        };

        if ($owner->getId() === $alreadyMentioned) {
            return;
        }

        $owner->notify(new PlanProgressNotification(
            $this->plan,
            'Plan inactive',
            sprintf('The plan "%s" has had no activity in over %dh.', $this->plan->title, $this->thresholdHours),
            [
                'plan_id' => $this->plan->getId(),
                'plan_uuid' => $this->plan->uuid,
                'change_type' => 'stale',
                'inactive_hours' => $this->thresholdHours,
                'action' => $result,
            ],
        ));
    }

    private function postComment(?Users $author, string $content): void
    {
        new PostPlanActivityMessageAction(
            plan: $this->plan,
            content: $content,
            author: $author,
            verb: 'plan-inactivity-nudge',
            extraPayload: ['from_ia' => true],
        )->execute();
    }

    /**
     * The user's @mention handle (app displayname), falling back to their name so the comment still
     * reads sensibly even if a handle can't be resolved.
     */
    private function handleFor(Users $user): string
    {
        /** @var AppInterface $app */
        $app = $this->plan->app;

        try {
            $displayname = trim($user->getAppProfile($app)->displayname);
        } catch (Throwable) {
            $displayname = '';
        }

        if ($displayname !== '') {
            return '@' . $displayname;
        }

        $name = trim($user->firstname . ' ' . $user->lastname);

        return $name !== '' ? $name : 'team';
    }

    private function nudgedWithinWindow(): bool
    {
        $last = $this->lastEvent(self::EVENT_DETECTED);

        return $last !== null && $last->occurred_at->greaterThan(now()->subHours($this->thresholdHours));
    }

    private function lastEvent(string $eventType): ?Event
    {
        return Event::query()
            ->where('source_entity_type', Plan::class)
            ->where('source_entity_id', $this->plan->getId())
            ->where('event_type', $eventType)
            ->orderByDesc('occurred_at')
            ->first();
    }
}
