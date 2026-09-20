<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Scheduling\Actions\DeliverScheduledMessageToChannelAction;
use Kanvas\Users\Models\Users;

/**
 * Runs an agent turn nobody typed — a scheduled task firing, a background job finishing — and posts the
 * reply into the conversation.
 */
class WakeAgentInSessionAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly Session $session,
        private readonly string $instruction,
        private readonly Users $user,
        private readonly string $verb = 'scheduled-agent-reply',
    ) {
    }

    public function execute(): string
    {
        // sourceChannel MUST be passed when the session has one — otherwise the kernel activates
        // setThreadId and the agent loses its cross-session history on this woken turn.
        // privateUserTurn: the instruction is a USER turn only to drive the agent — no one typed it.
        $response = new AgentChatKernel(
            agent: $this->agent,
            session: $this->session,
            message: $this->instruction,
            user: $this->user,
            sourceChannel: $this->session->channel,
            persistConversation: false,
            privateUserTurn: true,
        )->execute();

        // An agent with no user of its own has no author to post as; the turn still ran.
        if ($this->session->channel !== null && $this->agent->user !== null && trim($response) !== '') {
            new DeliverScheduledMessageToChannelAction(
                channel: $this->session->channel,
                text: $response,
                author: $this->agent->user,
                agent: $this->agent,
                sessionUuid: $this->session->uuid,
                canalId: $this->session->canal_id,
                verb: $this->verb,
                fromAgentTurn: true,
            )->execute();
        }

        return $response;
    }
}
