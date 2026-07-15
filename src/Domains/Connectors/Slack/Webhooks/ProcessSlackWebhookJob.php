<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Slack\Actions\AgentChannelResponderAction;
use Kanvas\Connectors\Slack\Actions\CreateMessageFromSlackEventAction;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Enums\EventTypeEnum;
use Kanvas\Connectors\Slack\Services\SlackSignatureService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

#[WorkflowAction]
class ProcessSlackWebhookJob extends ProcessWebhookJob
{
    private const int DEDUPE_TTL_SECONDS = 300;

    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $event = $payload['event'] ?? [];

        if (EventTypeEnum::isLifecycle($event['type'] ?? null)) {
            $this->receiver->is_active = false;
            $this->receiver->saveOrFail();

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

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        return SlackSignatureService::isValidRequest($request, $receiver);
    }

    #[Override]
    public static function handshakeResponse(Request $request, ReceiverWebhook $receiver): ?array
    {
        if ($request->input('type') !== EventTypeEnum::URL_VERIFICATION->value) {
            return null;
        }

        return ['challenge' => (string) $request->input('challenge')];
    }

    /**
     * Slack redelivers an event when it doesn't see a 200 within 3s, and the retry is a fresh
     * dispatch — without this the agent answers the same message twice.
     */
    private function isFirstDelivery(string $eventId): bool
    {
        if ($eventId === '') {
            return true;
        }

        return Cache::add('slack:event:' . $eventId, true, self::DEDUPE_TTL_SECONDS);
    }

    /**
     * Our own reply comes back as an inbound event. Without this the agent talks to itself forever.
     */
    private function isFromBot(array $event): bool
    {
        $botUserId = (string) ($this->receiver->configuration[ConfigurationEnum::BOT_USER_ID->value] ?? '');

        return isset($event['bot_id'])
            || ($event['subtype'] ?? null) === 'bot_message'
            || ($botUserId !== '' && ($event['user'] ?? null) === $botUserId);
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
