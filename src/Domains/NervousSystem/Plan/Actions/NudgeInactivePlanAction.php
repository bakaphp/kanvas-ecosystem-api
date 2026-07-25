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
 * Acts on ONE plan that's gone silent past the threshold. What it does depends on who owns the plan:
 *  - human owner       → post a comment @mentioning them to ask for a status;
 *  - executor agent    → re-wake it (it stopped and never reported); if a prior re-wake already went
 *                        unanswered, escalate to the project owner instead of re-waking a dead agent;
 *  - unassigned        → @mention the project owner to assign or unblock it.
 * In every case the project's human PM is notified so oversight always knows work went quiet.
 *
 * Anti-spam is deliberate (we've been bitten before): a plan.staleness.detected ledger event gates each
 * plan to at most ONE nudge per threshold window, and the re-wake→escalate progression is tracked by a
 * plan.staleness.rewoke event so a dead agent isn't re-woken forever.
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

        $mentionedHumanId = null;
        $result = $this->act($owner, $pmUser, $mentionedHumanId);

        // Notify the project's human PM (owner) — unless they're the exact person we just @mentioned in
        // the plan comment, who already got a mention notification.
        if ($owner !== null && $owner->getId() !== $mentionedHumanId) {
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

        $this->plan->emitLedgerEvent(self::EVENT_DETECTED, payload: [
            'action' => $result,
            'inactive_hours' => $this->thresholdHours,
        ]);

        return $result;
    }

    private function act(?Users $owner, ?Users $pmUser, ?int &$mentionedHumanId): string
    {
        if ($this->plan->assigned_users_id !== null) {
            $human = $this->plan->assignedUser;
            if ($human !== null) {
                $mentionedHumanId = $human->getId();
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
        }

        if ($this->plan->agent_id !== null) {
            return $this->handleAgentAssigned($owner, $pmUser, $mentionedHumanId);
        }

        return $this->pingOwner(
            $pmUser,
            $owner,
            $mentionedHumanId,
            sprintf(
                'this plan is unassigned and has had no activity in over %dh. Can you assign it or say '
                . 'what it\'s waiting on?',
                $this->thresholdHours,
            ),
        );
    }

    private function handleAgentAssigned(?Users $owner, ?Users $pmUser, ?int &$mentionedHumanId): string
    {
        // A re-wake produces no channel message, so if one already fired since the last real activity
        // and the plan is STILL silent, the agent is dead — escalate to a human instead of re-waking it.
        $rewoke = $this->lastEvent(self::EVENT_REWOKE);
        $activity = new StalePlanNudgeService()->lastActivityAt($this->plan);

        if ($rewoke !== null && $rewoke->occurred_at->greaterThan($activity)) {
            return $this->pingOwner(
                $pmUser,
                $owner,
                $mentionedHumanId,
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

    private function pingOwner(?Users $pmUser, ?Users $owner, ?int &$mentionedHumanId, string $ask): string
    {
        if ($owner === null) {
            return self::RESULT_PINGED_OWNER;
        }

        $mentionedHumanId = $owner->getId();
        $this->postComment(
            $pmUser,
            sprintf('%s the plan "%s" %s', $this->handleFor($owner), $this->plan->title, $ask),
        );

        return $this->plan->agent_id !== null ? self::RESULT_ESCALATED_AGENT : self::RESULT_PINGED_OWNER;
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
