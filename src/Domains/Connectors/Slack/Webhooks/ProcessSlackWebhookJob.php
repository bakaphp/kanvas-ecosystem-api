<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Webhooks;

use Kanvas\Connectors\Slack\Actions\AgentChannelResponderAction;
use Kanvas\Connectors\Slack\Actions\CreateMessageFromSlackEventAction;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Enums\EventTypeEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Override;

#[WorkflowAction(
    name: 'Slack Agent Webhook',
    description: 'Receiver for Slack: a message in a connected channel or DM reaches the agent, which reads it '
        . 'and replies IN SLACK. This is the internal-teammate surface — staff talk to the agent where '
        . 'they already work. It keeps one thread per Slack conversation.',
)]
class ProcessSlackWebhookJob extends SlackWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $event = $payload['event'] ?? [];

        if (EventTypeEnum::isLifecycle($event['type'] ?? null)) {
            $this->deactivateReceiver();

            return ['message' => 'Slack app uninstalled, receiver deactivated'];
        }

        if (! $this->isFirstDelivery((string) ($payload['event_id'] ?? ''))) {
            return ['message' => 'Duplicate event'];
        }

        if ($this->isFromBot($event)) {
            return ['message' => 'Bot message ignored'];
        }

        if (! $this->shouldAnswer($event)) {
            return ['message' => 'Event not addressed to the agent'];
        }

        $agentId = (int) ($this->receiver->configuration[ConfigurationEnum::AGENT_ID->value] ?? 0);

        if ($agentId === 0) {
            return ['message' => 'Receiver has no agent configured'];
        }

        /** @var Agent $agent */
        $agent = Agent::getById($agentId, $this->receiver->app);

        $message = new CreateMessageFromSlackEventAction(
            $this->webhookRequest,
            $agent,
            $event
        )->execute();

        if ($message === null) {
            return ['message' => 'Sender has no Kanvas account'];
        }

        /** @var Channel $channel */
        $channel = $message->channels()->firstOrFail();

        return new AgentChannelResponderAction(
            $channel,
            $message,
            $agent,
            $this->resolveSession(
                $channel,
                $message,
                $agent
            ),
        )->execute();
    }

    /**
     * One session per Slack thread. The kernel keys the agent's history off the entity (a DM's
     * Users, a room's Channel) rather than this session, so continuity survives new threads.
     */
    private function resolveSession(Channel $channel, Message $message, Agent $agent): Session
    {
        // Non-null by construction: CreateMessageFromSlackEventAction always attaches an entity.
        $entity = $message->entity();

        if ($entity === null) {
            throw new ValidationException('Slack message ' . (int) $message->getId() . ' has no entity');
        }

        return new CreateSessionAction(
            SessionDto::from([
                'app' => $this->receiver->app,
                'company' => $this->receiver->company,
                'channel' => $channel,
                'agent' => $agent,
                'entity_namespace' => $entity::class,
                'entity_id' => $entity->getId(),
                'canal_id' => (string) ($message->message['chat_jid'] ?? ''),
                'user' => [
                    'id' => $message->user->getId(),
                    'name' => trim((string) $message->user->firstname . ' ' . (string) $message->user->lastname),
                    'email' => $message->user->email,
                ],
            ]),
        )->execute();
    }

    /**
     * DMs always, rooms only when @mentioned. The manifest subscribes to `app_mention` + `message.im`
     * and nothing else, so a room message that isn't a mention never reaches us in the first place —
     * this is the belt to that manifest's braces.
     */
    private function shouldAnswer(array $event): bool
    {
        return ($event['channel_type'] ?? '') === 'im'
            || ($event['type'] ?? '') === EventTypeEnum::APP_MENTION->value;
    }
}
