<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Single primitive for posting a Message tied to a Plan onto a Social channel as the agent's user
 * (or an explicit author). Defaults to the Plan's own Activities channel, but takes any channel so
 * the swarm-milestone path can post to the swarm channel. Used by the chat-wake reply, the kanban
 * ingest summary, and the swarm completion post. Best-effort: failures are reported, not fatal.
 *
 * The author defaults to the agent's user so it matches the loop guard in ReplyToPlanCommentActivity
 * (an agent-authored message must not refire the agent).
 */
class PostPlanActivityMessageAction
{
    /**
     * @param array<string, mixed> $extraPayload merged into the message body alongside content/from_me
     */
    public function __construct(
        private readonly Plan $plan,
        private readonly string $content,
        private readonly ?Users $author = null,
        private readonly ?Channel $channel = null,
        private readonly string $verb = 'agent_reply',
        private readonly array $extraPayload = [],
        private readonly ?Message $replyTo = null,
    ) {
    }

    /**
     * The ROOT of the thread a reply belongs to, never the message replied to directly.
     *
     * Threads stay one level deep — the same anchor `RespondToMentionJob` uses. Parenting to the
     * message itself would nest a conversation deeper on every turn; parenting to nothing starts a
     * new thread per message, which is how one exchange ends up as five disconnected roots.
     */
    private function threadRootOf(?Message $replyTo): ?int
    {
        return $replyTo?->joinAncestors()->last()?->getId();
    }

    public function execute(): ?Message
    {
        try {
            $channel = $this->channel ?? $this->plan->socialChannels->first();
            $author = $this->author ?? $this->plan->agent?->user ?? $this->plan->user;

            if ($channel === null || $author === null || trim($this->content) === '') {
                return null;
            }

            return new PostChannelMessageAction(
                channel: $channel,
                author: $author,
                verb: $this->verb,
                content: $this->content,
                // Every caller of this action is a job, an action or an agent tool — a person comments
                // through the generic channel mutation instead. Stamping it here is what lets the wake
                // listener tell an agent's comment from a human's WITHOUT asking who the author is:
                // agents share users with real people, so user identity answers that question wrongly.
                extraPayload: array_merge(['from_me' => true, 'from_agent' => true], $this->extraPayload),
                parentId: $this->threadRootOf($this->replyTo),
                runWorkflow: true,
                messageTypeName: $this->verb,
                template: '{{message}}',
                templatesPlura: '{{message}}',
                languagesId: 1,
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
