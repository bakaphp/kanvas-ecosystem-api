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
    ) {
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
                extraPayload: array_merge(['from_me' => true], $this->extraPayload),
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
