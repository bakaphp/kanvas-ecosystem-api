<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs\Traits;

use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Services\NativeChannelDeliveryService;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Notifications\PlanProgressNotification;
use Kanvas\NervousSystem\Plan\Support\MentionHandle;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Everywhere a plan's outcome is announced, shared by the done and blocked alerts: its own board, the
 * conversation it was asked for in, and a direct notification to the person who asked.
 */
trait AnnouncesPlanOutcome
{
    private ?Users $cachedAsker = null;
    private bool $askerResolved = false;

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

        $session = $plan->originSession;

        // Addressed to whoever asked, so the ping in their own conversation reaches THEM. Falls back
        // to the plan owner only when no asker was recorded (a cron, a workflow).
        $content = ($this->mentionFor($plan, $this->asker($plan)) ?? '') . $body;

        $posted = new PostPlanActivityMessageAction(
            plan: $plan,
            content: $content,
            // The voice of the conversation: the person is talking to the PM, so a worker posting in
            // their DM is a stranger interrupting.
            author: $plan->createdByAgent?->user ?? $plan->agent?->user ?? $plan->user,
            channel: $origin,
            // A chat thread renders by SESSION — same uuid, same `ai-chat` type — or it sits on the
            // channel outside the conversation. `plan-done-alert` is the board's verb, not this one's.
            verb: $session !== null ? 'ai-chat' : $verb,
            extraPayload: array_filter([
                'plan_id' => $plan->getId(),
                'from_ia' => true,
                'session_id' => $session?->uuid,
                'agent_id' => $plan->createdByAgent?->getId(),
            ], static fn (mixed $value): bool => $value !== null),
        )->execute();

        $this->deliverToTheConversation($plan, $content, $posted);
    }

    /**
     * The same report, delivered everywhere the conversation actually lives.
     *
     * Three surfaces, none of which the channel row covers on its own: the TRANSCRIPT is what the chat
     * renders (`?session=` in its URL is the `agent_conversations` row), the BROADCAST is what makes it
     * appear without a reload, and the CONNECTOR PUSH is what reaches a conversation that happened in
     * Slack or WhatsApp — those channels are one-way mirrors, so writing to Kanvas shows nothing there.
     *
     * All three need the session: it carries the conversation key and the `canal_id` the push is
     * addressed by. Best-effort throughout — a plan that finished must not be un-finished by a
     * delivery failure.
     */
    protected function deliverToTheConversation(Plan $plan, string $content, ?Message $posted): void
    {
        $agent = $plan->createdByAgent;
        $session = $plan->originSession;

        if ($session === null || $agent === null || (string) $session->uuid === '') {
            return;
        }

        try {
            new KanvasConversationStore()->appendAssistantMessageForSession(
                appsId: (int) $plan->apps_id,
                companiesId: (int) $plan->companies_id,
                sessionId: (string) $session->uuid,
                agentClass: (string) ($agent->type?->handler ?? $agent::class),
                content: $content,
                agentId: $agent->getId(),
                userId: $this->asker($plan)?->getId(),
            );
        } catch (Throwable $e) {
            report($e);
        }

        try {
            AgentChatResponseEvent::dispatch(
                $agent,
                (string) $session->uuid,
                '',
                $content,
                $posted,
            );
        } catch (Throwable $e) {
            report($e);
        }

        // A connector channel is a mirror, and it is one-way: on a conversation that happened in Slack
        // or WhatsApp, everything above lands in Kanvas and nothing appears where the person is. The
        // push is what puts it in the thread, addressed by the session's `canal_id`. Its result is
        // deliberately ignored — unlike a scheduled reminder, a finished plan is worth both the message
        // and the notification.
        try {
            NativeChannelDeliveryService::deliver(
                $plan->originChannel,
                $content,
                $agent,
                $session->canal_id,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The human who asked for the work, when one was recorded.
     *
     * Memoized: one announcement reads it for the mention, the transcript row and the notification,
     * and each job only ever announces a single plan.
     */
    protected function asker(Plan $plan): ?Users
    {
        if ($this->askerResolved) {
            return $this->cachedAsker;
        }

        $this->askerResolved = true;

        if ($plan->origin_users_id === null) {
            return null;
        }

        try {
            return $this->cachedAsker = Users::getById((int) $plan->origin_users_id);
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
     * Resolved through `MentionHandle` rather than off the raw displayname: the parser matches a single
     * `@token`, so "Liliana Garcia" becomes `@Liliana` and reaches nobody. Half the profiles on app 2
     * are unmentionable that way, and a broken mention is worse than none — it reads as delivered.
     * Null means "cannot be mentioned"; the notification path is what actually reaches them.
     *
     * Defaults to the plan's owner, which is right for the plan's own board. In a conversation pass the
     * asker: agent-created work is owned by an agent, so the default would ping the PM, not the person.
     */
    protected function mentionFor(Plan $plan, ?Users $subject = null): ?string
    {
        $handle = MentionHandle::forUser($subject ?? $plan->user, $plan->app);

        return $handle !== null ? '@' . $handle . ' ' : null;
    }
}
