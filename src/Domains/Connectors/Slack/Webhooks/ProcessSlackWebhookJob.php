<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Slack\Actions\AgentChannelResponderAction;
use Kanvas\Connectors\Slack\Actions\CreateMessageFromSlackEventAction;
use Kanvas\Connectors\Slack\Client;
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
use Throwable;

#[WorkflowAction]
class ProcessSlackWebhookJob extends ProcessWebhookJob
{
    private const int DEDUPE_TTL_SECONDS = 300;

    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $event = $payload['event'] ?? [];
        $type = (string) ($event['type'] ?? '');

        if (EventTypeEnum::isLifecycle($type)) {
            $this->receiver->is_active = false;
            $this->receiver->saveOrFail();

            return ['message' => 'Slack app uninstalled, receiver deactivated'];
        }

        if (! $this->isFirstDelivery((string) ($payload['event_id'] ?? ''))) {
            return ['message' => 'Duplicate event'];
        }

        // Everything below this point that can be decided from the payload alone is decided before the
        // agent lookup: with `message.channels` subscribed, most inbound events are traffic we drop,
        // and each one paying for a DB read would be a query per message posted in the workspace.
        if ($this->isFromBot($event)) {
            return ['message' => 'Bot message ignored'];
        }

        if ($type === EventTypeEnum::CHANNEL_CREATED->value) {
            return $this->joinNewChannel($event);
        }

        if ($this->isMentionTwin($type)) {
            return ['message' => 'Mention already handled as a channel message'];
        }

        if (! EventTypeEnum::isUtterance($event)) {
            return ['message' => 'Channel bookkeeping event ignored'];
        }

        $isAddressedToAgent = $this->isAddressedToAgent($event);

        if (! $isAddressedToAgent && ! $this->listensToAllChannels()) {
            return ['message' => 'Event not addressed to the agent'];
        }

        $agent = $this->agent();

        if ($agent === null) {
            return ['message' => 'Receiver has no agent configured'];
        }

        $message = new CreateMessageFromSlackEventAction(
            $this->webhookRequest,
            $agent,
            $event,
            // Overheard messages are recorded, not answered, so nothing is waiting on their files.
            // Re-hosting every attachment posted anywhere in the workspace is a bandwidth and storage
            // bill for content no turn is about to read.
            downloadAttachments: $isAddressedToAgent,
        )->execute();

        if ($message === null) {
            return ['message' => 'Sender has no Kanvas account'];
        }

        /** @var Channel $channel */
        $channel = $message->channels()->firstOrFail();

        if (! $isAddressedToAgent) {
            return [
                'message' => 'Message recorded',
                'message_id' => $message->getId(),
            ];
        }

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
     * A workspace that opted into listening shouldn't go deaf the moment someone opens a new channel.
     */
    private function joinNewChannel(array $event): array
    {
        $channelId = (string) ($event['channel']['id'] ?? '');

        if (! $this->listensToAllChannels() || $channelId === '') {
            return ['message' => 'Channel creation ignored'];
        }

        $agent = $this->agent();

        if ($agent === null) {
            return ['message' => 'Channel creation ignored'];
        }

        try {
            Client::getInstanceByAgent($agent)->joinConversation($channelId);
        } catch (Throwable $e) {
            // Not fatal to anything else — the workspace keeps working, this one channel just stays
            // unheard until the next sweep — but it IS a real failure worth seeing.
            report($e);

            return ['message' => 'Could not join new channel ' . $channelId];
        }

        return ['message' => 'Joined new channel ' . $channelId];
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
        $botUserId = $this->botUserId();

        return isset($event['bot_id'])
            || ($event['subtype'] ?? null) === 'bot_message'
            || ($botUserId !== '' && ($event['user'] ?? null) === $botUserId);
    }

    /**
     * Whether the agent should take a turn, as opposed to merely overhearing.
     *
     * DMs always; a room only when the bot is named. Listening to everything must not turn into
     * answering everything — an LLM turn per message in a busy workspace is a cost blowout and
     * unbearable to sit next to.
     */
    private function isAddressedToAgent(array $event): bool
    {
        if (($event['channel_type'] ?? '') === 'im') {
            return true;
        }

        if (($event['type'] ?? '') === EventTypeEnum::APP_MENTION->value) {
            return true;
        }

        $botUserId = $this->botUserId();

        // While listening we read mentions off the `message` event, since its app_mention twin is
        // dropped below.
        return $botUserId !== ''
            && str_contains((string) ($event['text'] ?? ''), '<@' . $botUserId . '>');
    }

    /**
     * A mention in a channel the bot is in arrives TWICE — once as `app_mention`, once as
     * `message.channels` — under two different event ids, so the event-id dedupe can't see it. While
     * listening we keep the `message` copy (it's the one that also carries non-mention traffic, so
     * ingest stays single-source) and drop the `app_mention` twin.
     */
    private function isMentionTwin(string $type): bool
    {
        return $type === EventTypeEnum::APP_MENTION->value && $this->listensToAllChannels();
    }

    private function agent(): ?Agent
    {
        $agentId = (int) ($this->receiver->configuration[ConfigurationEnum::AGENT_ID->value] ?? 0);

        if ($agentId === 0) {
            return null;
        }

        /** @var Agent $agent */
        $agent = Agent::getById($agentId, $this->receiver->app);

        return $agent;
    }

    private function listensToAllChannels(): bool
    {
        return (bool) ($this->receiver->configuration[ConfigurationEnum::LISTEN_ALL_CHANNELS->value] ?? false);
    }

    private function botUserId(): string
    {
        return (string) ($this->receiver->configuration[ConfigurationEnum::BOT_USER_ID->value] ?? '');
    }
}
