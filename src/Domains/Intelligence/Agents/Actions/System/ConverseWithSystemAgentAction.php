<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\System;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Services\UserAgentChannelService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * The single entrypoint for "a human says something to a system agent". CLI,
 * webhook jobs, and @mention responders all funnel through here so session
 * resolution and the kernel contract live in exactly one place.
 */
final class ConverseWithSystemAgentAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly Users $human,
        private readonly string $message,
        private readonly ?Model $subjectEntity = null,
        private readonly ?Channel $channel = null,
        private readonly ?Message $sourceMessage = null,
        private readonly bool $persistConversation = true,
    ) {
    }

    public function execute(): string
    {
        $app = $this->agent->app;
        $company = $this->agent->company;

        if ($app === null || $company === null) {
            throw new ValidationException(sprintf(
                'Agent %s is not bound to an app and company; it cannot hold a conversation.',
                $this->agent->getId(),
            ));
        }

        // The session entity drives the agent's context: the record it was dropped
        // onto (subject) if any, otherwise the human it is talking to.
        $entity = $this->subjectEntity ?? $this->human;

        $session = new UserAgentChannelService()->resolveSession(
            human: $this->human,
            agent: $this->agent,
            app: $app,
            company: $company,
            entity: $entity,
            channel: $this->channel,
        );

        return new AgentChatKernel(
            agent: $this->agent,
            session: $session,
            message: $this->message,
            user: $this->human,
            currentLead: $this->subjectEntity instanceof Lead ? $this->subjectEntity : null,
            sourceChannel: $this->channel,
            sourceMessage: $this->sourceMessage,
            persistConversation: $this->persistConversation,
        )->execute();
    }
}
