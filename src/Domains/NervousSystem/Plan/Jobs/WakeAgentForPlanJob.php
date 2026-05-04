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
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Throwable;

/**
 * Single entry point for waking the agent assigned to a Plan. Used by:
 *   - WakeAgentForPlanListener (plan created / approved)
 *   - ReplyToPlanCommentActivity (human comment on the Activities channel)
 *
 * Both paths land on the same per-plan Session so the agent's LLM context
 * is continuous across all wake-ups for the same plan.
 *
 * The agent's reply is posted back on the plan's Activities channel by the
 * agent's user — which has the `is_agent=true` custom field set, so the
 * comment-reply activity will skip it on save and the loop is broken.
 */
class WakeAgentForPlanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string REASON_PLAN_ASSIGNED = 'plan_assigned';
    public const string REASON_COMMENT = 'comment';

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

        $response = new ProcessAgentChatAction(
            agent: $agent,
            session: $session,
            message: $message,
            app: $this->plan->app,
            company: $this->plan->company,
            user: $owner,
        )->execute();

        $this->postReplyOnActivitiesChannel($response);
    }

    protected function resolveSession(): Session
    {
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
     * channel. The poster is the agent's user (which has is_agent=true),
     * so the comment-reply activity will skip this message on save.
     */
    protected function postReplyOnActivitiesChannel(string $response): void
    {
        try {
            $channel = $this->plan->socialChannels->first();

            if ($channel === null || $response === '') {
                return;
            }

            $agentUser = $this->plan->agent?->user;
            if ($agentUser === null) {
                return;
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
                    channel_slug: $channel->slug,
                ),
            )->execute();

            $channel->addMessage($message, $agentUser);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
