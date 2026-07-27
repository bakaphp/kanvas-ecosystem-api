<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Contracts;

use Kanvas\Social\Messages\Models\Message;

/**
 * What "approve" DOES for one kind of agent-approval request. Any agent can gate an action behind human
 * sign-off by posting a locked message (via RequestAgentApprovalAction) that names its handler; when a
 * human approves, ApproveAgentMessageAction runs `approve()`. Decoupled by design — each domain ships
 * its own handler (send the email, forward the signal, …); the approval mechanism never grows a match
 * arm per agent.
 */
interface AgentApprovalHandler
{
    /**
     * Perform the approved action. Receives the request's stored context merged with the approver's
     * input (the approver's values win — e.g. a human redirecting to a different project).
     *
     * @param array<string, mixed> $context
     */
    public function approve(Message $message, array $context): void;
}
