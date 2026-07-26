<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Contracts\AgentApprovalHandler;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * The general "agent asks a human to approve an action" primitive. Posts a LOCKED message on a channel
 * that carries the handler to run on approval plus the context it needs. A human reviews it and calls
 * ApproveAgentMessageAction, which runs the handler and unlocks. Reusable by any agent — the orchestrator
 * (approve a routing decision), a sales agent (approve an outbound), etc. — without touching the approval
 * mechanism itself.
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
        if (! is_subclass_of($this->handler, AgentApprovalHandler::class)) {
            throw new ValidationException(
                "Approval handler {$this->handler} must implement AgentApprovalHandler.",
            );
        }

        $message = new PostChannelMessageAction(
            channel: $this->channel,
            author: $this->author,
            verb: $this->verb,
            content: $this->content,
            extraPayload: [
                'from_ia' => true,
                'approval' => [
                    'kind' => $this->kind,
                    'handler' => $this->handler,
                    'context' => $this->context,
                    'status' => 'pending',
                ],
            ],
            runWorkflow: false,
            entity: $this->entity,
            messageTypeName: $this->verb,
        )->execute();

        $message->setLock();

        return $message;
    }
}
