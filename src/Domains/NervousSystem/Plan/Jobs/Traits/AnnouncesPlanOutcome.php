<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs\Traits;

use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Notifications\PlanProgressNotification;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * The three places a plan's outcome is announced, shared by the done and blocked alerts.
 */
trait AnnouncesPlanOutcome
{
    /** The plan's own board — its permanent record, addressed to whoever owns it. */
    protected function postToPlanBoard(
        Plan $plan,
        string $body,
        string $verb,
        string $alert,
    ): void {
        try {
            new PostPlanActivityMessageAction(
                plan: $plan,
                content: ($this->mentionFor($plan) ?? '') . $body,
                author: $plan->agent?->user ?? $plan->user,
                verb: $verb,
                extraPayload: [
                    'alert' => $alert,
                    'plan_id' => $plan->getId(),
                    // Agent-authored: RespondToAgentMentionListener's anti-loop guard keys on
                    // from_ia, so without it this alert WAKES the agent it mentions. The human
                    // mention still notifies — that listener reads from_ia messages on purpose.
                    'from_ia' => true,
                ],
            )->execute();
        } catch (Throwable $e) {
            // Best-effort: the origin post and the direct notification still land.
            report($e);
        }
    }

    /**
     * Also say it where the plan was asked for. The Activities channel has no subscribers, so a report
     * that lands only there reaches no one.
     */
    protected function alsoPostToOriginConversation(Plan $plan, string $body, string $verb): void
    {
        $origin = $plan->originChannel;

        if ($origin === null) {
            return;
        }

        // The plan's own channel can already be this one; posting to both would say it twice.
        $ownsIt = $plan->socialChannels->contains(
            fn (Channel $channel): bool => (int) $channel->getKey() === (int) $origin->getKey(),
        );

        if ($ownsIt) {
            return;
        }

        // Addressed to whoever asked, so the ping in their own conversation reaches THEM. Falls back
        // to the plan owner only when no asker was recorded (a cron, a workflow).
        $mention = $this->mentionFor($plan, $this->asker($plan));

        new PostPlanActivityMessageAction(
            plan: $plan,
            content: ($mention ?? '') . $body,
            author: $plan->agent?->user ?? $plan->user,
            channel: $origin,
            verb: $verb,
            extraPayload: ['plan_id' => $plan->getId(), 'from_ia' => true],
        )->execute();
    }

    /**
     * The human who asked for the work, when one was recorded.
     */
    protected function asker(Plan $plan): ?Users
    {
        if ($plan->origin_users_id === null) {
            return null;
        }

        try {
            return Users::getById((int) $plan->origin_users_id);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Tell the person who asked, bypassing the @mention pipeline.
     *
     * `NotifyMentionedUsersListener` drops mentions resolving to an agent user, and agents share human
     * accounts — ten sit on user 2 — so a real person gets classified as a bot and silently skipped.
     */
    protected function notifyTheAsker(Plan $plan, string $title, string $message): void
    {
        $asker = $this->asker($plan);

        if ($asker === null) {
            return;
        }

        // Their own plan finishing is not news to them, and the agent that owns it is not a person.
        if ($asker->getId() === $plan->agent?->user?->getId()) {
            return;
        }

        try {
            $asker->notify(new PlanProgressNotification(
                $plan,
                $title,
                $message,
                [
                    'plan_id' => $plan->getId(),
                    'plan_uuid' => $plan->uuid,
                    'status' => $plan->status,
                ],
                ['mail', 'push', 'database', 'slack'],
            ));
        } catch (Throwable $e) {
            // A plan that finished must not be un-finished by a mail failure.
            report($e);
        }
    }

    /**
     * Mentioning is what actually notifies — a name in the text does nothing.
     *
     * Defaults to the plan's owner, which is right for the plan's own board. In a conversation it is
     * wrong: agent-created work is owned by an agent, so the alert posted into a person's chat pinged
     * the PM rather than them.
     */
    protected function mentionFor(Plan $plan, ?Users $subject = null): ?string
    {
        $owner = $subject ?? $plan->user;

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
