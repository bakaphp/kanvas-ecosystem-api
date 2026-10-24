<?php

declare(strict_types=1);

namespace Kanvas\Communication\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Actions\AgentChannelResponderAction;
use Kanvas\Communication\Support\AutoReplyGate;
use Kanvas\Communication\Support\AutoReplyPolicy;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Traits\HandlesSupportModeDelayedResponseTrait;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Intelligence\Sessions\Models\Session as SessionModel;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\KanvasActivity;

/**
 * The one responder for every medium: the conversation knows the connection it arrived on, and the
 * reply leaves through that connection's transport. What differs between SMS, email and WhatsApp is
 * the transport's business, not this activity's.
 */
#[WorkflowAction(
    name: 'Agent Conversation Responder',
    description: 'Has an agent answer an inbound message on the conversation it arrived on — the same '
        . 'number, address or WhatsApp line, whatever the medium. Use it for inbound messages the company '
        . 'wants answered. Do NOT use it when the agent should read and act somewhere else — it sends a '
        . 'reply to the customer.',
    params: [
        'channelAgentMapping' => 'Optional per-chat agent overrides. Every other conversation is answered by the '
            . 'agent the company setting sales_agent_id names, else the company\'s agent named Sally.',
        'message' => 'Supplied by the trigger — the inbound message. Not something you set.',
    ],
)]
class AgentChannelResponderActivity extends KanvasActivity
{
    use HandlesSupportModeDelayedResponseTrait;

    public $tries = 3;

    public function execute(Channel $channel, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $message = $params['message'] ?? null;

        if (! $message instanceof Message) {
            return ['message' => 'Message not found', 'entity' => null];
        }

        $integrationCompany = $this->integrationCompanyFor($channel, $app, $message);

        if ($integrationCompany === null) {
            return ['message' => 'The message did not arrive on a connection', 'entity' => null];
        }

        return $this->runForIntegrationCompany(
            entity: $channel,
            app: $app,
            integrationCompany: $integrationCompany,
            integrationOperation: fn (Channel $channel, Apps $app): array => $this->respond(
                $channel,
                $app,
                $message,
                $params
            ),
            additionalParams: $params
        );
    }

    /**
     * Where the run is recorded: the integration behind the conversation's connection.
     */
    protected function integrationCompanyFor(Channel $channel, Apps $app, Message $message): ?IntegrationsCompany
    {
        $connectionId = $message->message['communication']['connection_id'] ?? null;

        return $connectionId !== null
            ? Connection::query()->find((int) $connectionId)?->integrationCompany
            : null;
    }

    /**
     * @return class-string<AgentChannelResponderAction>
     */
    protected function responderAction(): string
    {
        return AgentChannelResponderAction::class;
    }

    private function respond(Channel $channel, Apps $app, Message $message, array $params): array
    {
        if ($message->message['from_me'] ?? false) {
            return ['message' => 'Message is from our side of the conversation', 'entity' => null];
        }

        $chatJid = $message->message['chat_jid'] ?? null;
        $allowedChannels = $params['channelId'] ?? [];

        if ((bool) ($params['filterByChannel'] ?? false) && ! in_array($chatJid, $allowedChannels)) {
            return ['message' => 'Agent is not running on this channel', 'entity' => null];
        }

        $message->addTag('engagement');

        $refusal = AutoReplyGate::refusal($message);

        if ($refusal !== null) {
            return ['message' => 'Automatic replies are off for this conversation: ' . $refusal, 'entity' => null];
        }

        // The conversation is the person's; the lead, when there is one, is context on it.
        $lead = $message->entity(Lead::class);
        $person = $message->entity(People::class) ?? $lead?->people;

        if ($person === null) {
            return $this->failWorkflow(['message' => 'The message is not filed on a person or lead', 'entity' => null]);
        }

        if ($lead instanceof Lead && $lead->isAiMuted()) {
            return ['message' => 'Lead turned off AI agent responses', 'entity' => null];
        }

        // A chat mapped to its own agent keeps it; every other conversation is the company's agent.
        $channelAgentMapping = $params['channelAgentMapping'] ?? [];
        $mappedAgentId = $channelAgentMapping[$chatJid]['agent_id'] ?? null;
        $agent = $mappedAgentId !== null
            ? Agent::getById($mappedAgentId, $app)
            : app(AutoReplyPolicy::class)->conversationAgent($app, $channel->company);
        $defaultAgentId = $agent->getId();
        $session = $this->session($channel, $app, $message, $person, $agent);

        if ($lead instanceof Lead) {
            $delayedResponse = $this->handleSupportModeDelayedResponse(
                $lead,
                $channel,
                $message,
                $app,
                $defaultAgentId,
                $channelAgentMapping,
                $chatJid,
                $params,
                $this->responderAction(),
                $session
            );

            if ($delayedResponse !== null) {
                return $delayedResponse;
            }
        }

        $action = $this->responderAction();

        try {
            return new $action($channel, $message, $agent, $session)->execute($params);
        } catch (AgentReplySkippedException | ValidationException $skipped) {
            return $this->failWorkflow(['message' => $skipped->getMessage(), 'entity' => null]);
        }
    }

    private function session(
        Channel $channel,
        Apps $app,
        Message $message,
        People $person,
        Agent $agent
    ): SessionModel {
        return new CreateSessionAction(
            Session::from([
                'app' => $app,
                'company' => $channel->company,
                'channel' => $channel,
                'entity_namespace' => People::class,
                'entity_id' => (string) $person->getKey(),
                'canal_id' => $message->message['chat_jid'] ?? null,
                'user' => [
                    'name' => $person->getName(),
                    'id' => $person->getId(),
                    'email' => $person->getEmails()->first()?->value,
                ],
                'agent' => $agent,
            ])
        )->execute();
    }
}
