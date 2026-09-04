<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Agents\Contracts\AgentApprovalHandler;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Social\Messages\Actions\RequestMessageApprovalAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\MessageApproval;
use Kanvas\Users\Models\Users;

/**
 * The general "agent asks a human to approve an action" primitive. Posts a message on a channel and
 * opens an approval on it carrying the handler to run plus the context it needs. Reusable by any
 * agent — the orchestrator (approve a routing decision), a sales agent (approve an outbound) — without
 * touching the approval mechanism itself.
 *
 * The approval is an ordinary approval_requests row; this only exists because posting the message
 * first is the agent-shaped part of the flow.
 */
class RequestAgentApprovalAction
{
    /**
     * @param string $kind a stable discriminator the FRONTEND switches on to render the right approve
     *                      UI (e.g. 'project_routing') — never a PHP class name
     * @param class-string<AgentApprovalHandler> $handler the handler to run on approval
     * @param array<string, mixed> $context data the handler needs (e.g. target project id, content)
     */
    public function __construct(
        private readonly Channel $channel,
        private readonly Users $author,
        private readonly string $content,
        private readonly string $kind,
        private readonly string $handler,
        private readonly array $context = [],
        private readonly string $verb = 'agent-approval-request',
        private readonly ?Model $entity = null,
    ) {
    }

    public function execute(): Message
    {
        // Ahead of posting, not inside the gating call below: a bad handler caught after the message
        // exists leaves an ungated draft on the channel that nothing can ever approve.
        MessageApproval::assertHandler($this->handler);

        $message = new PostChannelMessageAction(
            channel: $this->channel,
            author: $this->author,
            verb: $this->verb,
            content: $this->content,
            extraPayload: ['from_ia' => true],
            runWorkflow: false,
            entity: $this->entity,
            messageTypeName: $this->verb,
        )->execute();

        new RequestMessageApprovalAction(
            message: $message,
            kind: $this->kind,
            handler: $this->handler,
            context: $this->context,
        )->execute();

        return $message->refresh();
    }
}
