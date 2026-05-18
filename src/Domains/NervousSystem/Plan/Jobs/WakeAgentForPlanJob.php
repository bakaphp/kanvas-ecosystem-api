<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Actions\ProcessAgentChatAction;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Throwable;

/**
 * Single entry point for waking the agent assigned to a Plan. Used by:
 *   - WakeAgentOnPlanChange listener (plan created / approved)
 *   - ReplyToPlanCommentActivity (human comment on the Activities channel)
 *
 * Both paths land on the same per-plan Session so the agent's LLM context
 * is continuous across all wake-ups for the same plan.
 *
 * Emits two ledger events on success:
 *   plan.agent.invoked      — after the LLM call returns (carries duration_ms)
 *   plan.agent.replied      — after the reply is posted on the channel
 *
 * And one on failure:
 *   plan.agent.invocation_failed — when ProcessAgentChatAction throws
 */
class WakeAgentForPlanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string REASON_PLAN_ASSIGNED = 'plan_assigned';
    public const string REASON_COMMENT = 'comment';
    public const string REASON_APPROVED = 'approved';

    public function __construct(
        public readonly Plan $plan,
        public readonly string $reason,
        public readonly ?string $userMessage = null,
    ) {
    }

    public function handle(): void
    {
        $agent = $this->plan->agent;
        $owner = $this->plan->user ?? $agent?->user;

        if ($agent === null || $owner === null) {
            return;
        }

        $session = $this->resolveSession();
        $message = $this->buildMessage();

        $startedAt = microtime(true);

        try {
            $response = new ProcessAgentChatAction(
                agent: $agent,
                session: $session,
                message: $message,
                app: $this->plan->app,
                company: $this->plan->company,
                user: $owner,
            )->execute();
        } catch (Throwable $e) {
            $this->plan->emitLedgerEvent(
                eventType: 'plan.agent.invocation_failed',
                status: EventStatusEnum::ERROR,
                payload: [
                    'agent_id' => $this->plan->agent_id,
                    'session_id' => $session->getId(),
                    'reason' => $this->reason,
                ],
                error: [
                    'message' => $e->getMessage(),
                    'class' => $e::class,
                ],
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
            );

            throw $e;
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $this->plan->emitLedgerEvent(
            'plan.agent.invoked',
            payload: [
                'agent_id' => $this->plan->agent_id,
                'session_id' => $session->getId(),
                'reason' => $this->reason,
                'response_length' => strlen($response),
            ],
            durationMs: $durationMs,
        );

        $reply = $this->postReplyOnActivitiesChannel($response);

        if ($reply !== null) {
            $this->plan->emitLedgerEvent(
                'plan.agent.replied',
                payload: [
                    'agent_id' => $this->plan->agent_id,
                    'message_id' => $reply->id,
                    'message_uuid' => $reply->uuid,
                ],
            );
        }
    }

    protected function resolveSession(): Session
    {
        $owner = $this->plan->user ?? $this->plan->agent?->user;

        /** @var Session $session */
        $session = Session::firstOrCreate(
            [
                'apps_id' => $this->plan->apps_id,
                'companies_id' => $this->plan->companies_id,
                'entity_namespace' => Plan::class,
                'entity_id' => $this->plan->id,
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'agents_id' => $this->plan->agent_id,
                'channel_id' => null,
                'content' => '',
                'user' => $owner !== null ? [
                    'id' => $owner->getId(),
                    'name' => trim(($owner->firstname ?? '') . ' ' . ($owner->lastname ?? '')),
                    'email' => $owner->email ?? null,
                ] : [],
            ],
        );

        return $session;
    }

    protected function buildMessage(): string
    {
        if ($this->reason === self::REASON_COMMENT) {
            return sprintf(
                "[NS:plan_comment] plan_id=%d plan_uuid=%s\n\n%s",
                $this->plan->id,
                $this->plan->uuid,
                (string) $this->userMessage,
            );
        }

        if ($this->reason === self::REASON_APPROVED) {
            $reviewerNote = $this->plan->review_outcome !== null && $this->plan->review_outcome !== ''
                ? "Reviewer note: {$this->plan->review_outcome}\n"
                : '';

            return sprintf(
                "[NS:plan_approved] plan_id=%d plan_uuid=%s\n\n"
                . 'Your plan has been approved by the human reviewer. '
                . 'Resume execution. Use the nervous-system-working skill '
                . "to refresh plan context if you need to, then continue the work.\n\n"
                . "Title: %s\n%s%s",
                $this->plan->id,
                $this->plan->uuid,
                $this->plan->title,
                $reviewerNote,
                $this->plan->description !== null && $this->plan->description !== ''
                    ? "Description: {$this->plan->description}"
                    : '',
            );
        }

        return sprintf(
            "[NS:plan_assigned] reason=%s plan_id=%d plan_uuid=%s\n\n"
            . 'A plan has been assigned to you. Use the nervous-system-working '
            . 'skill to fetch its full context, plan the work, and execute. '
            . "Report progress on the Activities channel.\n\n"
            . "Title: %s\n%s",
            $this->reason,
            $this->plan->id,
            $this->plan->uuid,
            $this->plan->title,
            $this->plan->description !== null && $this->plan->description !== ''
                ? "Description: {$this->plan->description}"
                : '',
        );
    }

    /**
     * Persist the agent's response as a Message on the Plan's Activities
     * channel. The poster is the agent's user, which matches the loop
     * guard in ReplyToPlanCommentActivity so this message doesn't refire.
     *
     * Returns the saved Message (or null if any precondition failed).
     *
     * Note: do NOT pass channel_slug to CreateMessageAction here. When set,
     * it unconditionally calls CreateChannelAction, which trips on an 'Admin'
     * role lookup with a null company. We attach via $channel->addMessage().
     */
    protected function postReplyOnActivitiesChannel(string $response): ?Message
    {
        try {
            $channel = $this->plan->socialChannels->first();

            if ($channel === null || $response === '') {
                return null;
            }

            $agentUser = $this->plan->agent?->user;
            if ($agentUser === null) {
                return null;
            }

            $messageType = new CreateMessageTypeAction(
                new MessageTypeInput(
                    apps_id: $this->plan->app->getId(),
                    languages_id: 1,
                    name: 'agent_reply',
                    verb: 'agent_reply',
                    template: '{{message}}',
                    templates_plura: '{{message}}',
                ),
            )->execute();

            $message = new CreateMessageAction(
                new MessageInput(
                    app: $this->plan->app,
                    company: $this->plan->company,
                    user: $agentUser,
                    type: $messageType,
                    message: [
                        'content' => $response,
                        'from_me' => true,
                    ],
                ),
            )->execute();

            $channel->addMessage($message, $agentUser);

            return $message;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
