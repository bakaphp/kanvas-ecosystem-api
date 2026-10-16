<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Webhooks;

use Kanvas\Connectors\Slack\Actions\AgentChannelResponderAction;
use Kanvas\Connectors\Slack\Actions\CreateMessageFromSlackEventAction;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Enums\EventTypeEnum;
use Kanvas\Connectors\Slack\Jobs\BackfillSlackChannelHistoryJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Override;

#[WorkflowAction(
    name: 'Slack Agent Webhook',
    description: 'Receiver for Slack: every message in a room the agent was invited to is recorded on the '
        . 'Kanvas channel for that room, and the ones addressed to the agent — a DM, a group DM, an '
        . '@mention — are answered IN SLACK. This is the internal-teammate surface: staff talk to the '
        . 'agent where they already work, and it has the room in view when they tag it. It keeps one '
        . 'thread per Slack conversation.',
)]
class ProcessSlackWebhookJob extends SlackWebhookJob
{
    /**
     * Only the events that wake the agent ride the reply queue. Everything else — room chatter, an
     * invite, an edit — is a write, and a busy workspace would otherwise sit in front of answers.
     */
    public function __construct(ReceiverWebhookCall $webhookRequest)
    {
        parent::__construct($webhookRequest);

        if (! $this->isAddressed((array) ($this->webhookRequest->payload['event'] ?? []))) {
            $this->onQueue('slack-ingest');
        }
    }

    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $event = (array) ($payload['event'] ?? []);
        $type = (string) ($event['type'] ?? '');

        if (EventTypeEnum::isLifecycle($type)) {
            $this->deactivateReceiver();

            return ['message' => 'Slack app uninstalled, receiver deactivated'];
        }

        if (! $this->isFirstDelivery((string) ($payload['event_id'] ?? ''))) {
            return ['message' => 'Duplicate event'];
        }

        if ($type === EventTypeEnum::MEMBER_JOINED_CHANNEL->value) {
            return $this->onMemberJoined($event);
        }

        if (! in_array($type, [EventTypeEnum::MESSAGE->value, EventTypeEnum::APP_MENTION->value], true)) {
            return ['message' => 'Not a message event'];
        }

        $slackChannelId = (string) ($event['channel'] ?? '');

        if (! $this->isFirstSightingOfMessage($slackChannelId, (string) ($event['ts'] ?? ''))) {
            return ['message' => 'Duplicate message'];
        }

        $subtype = (string) ($event['subtype'] ?? '');

        if (EventTypeEnum::isNoise($subtype)) {
            return ['message' => 'Non-conversational subtype ignored'];
        }

        $addressed = $this->isAddressed($event);
        [$event, $tags] = $this->normalize($event, $subtype);

        if ($this->isFromBot($event)) {
            return ['message' => 'Bot message ignored'];
        }

        if ($this->isDenied($slackChannelId)) {
            return ['message' => 'Channel is on the deny list'];
        }

        $agentId = (int) ($this->receiver->configuration[ConfigurationEnum::AGENT_ID->value] ?? 0);

        if ($agentId === 0) {
            return ['message' => 'Receiver has no agent configured'];
        }

        /** @var Agent $agent */
        $agent = Agent::getById($agentId, $this->receiver->app);

        $message = new CreateMessageFromSlackEventAction(
            receiver: $this->receiver,
            agent: $agent,
            teamId: (string) ($payload['team_id'] ?? ''),
            event: $event,
            addressed: $addressed,
            tags: $tags,
        )->execute();

        if ($message === null) {
            return ['message' => $addressed ? 'Sender has no Kanvas account' : 'Empty message skipped'];
        }

        if ($type === EventTypeEnum::MESSAGE->value) {
            $this->ensureBackfillScheduled($event, $slackChannelId);
        }

        if (! $addressed) {
            return [
                'message' => 'Ingested',
                'entity' => $message->getId(),
            ];
        }

        /** @var Channel $channel */
        $channel = $message->channels()->firstOrFail();

        // AgentChatKernel is the authoritative gate; this early return exists only because the
        // responder posts a "working..." placeholder into the thread before it ever reaches the
        // kernel, so letting the skip happen there would leave a visible failed post per message.
        if (! $agent->is_active) {
            return ['message' => 'Agent is deactivated, message stored without a reply'];
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
     * The only thing that makes the agent speak: a DM, a group DM (one the agent opened to ask a
     * teammate in front of whoever wanted the answer, so every message in it is for the agent), or
     * its handle in a room. A room message without the handle is recorded and nothing more — people
     * learn one rule, tag it to get an answer. `app_mention` is the same tag arriving from a room the
     * bot is not a member of; once it is, the message event carries the handle itself.
     */
    private function isAddressed(array $event): bool
    {
        // Corrections stay silent even in a DM, where every real message is for the agent. Only
        // corrections: `file_share` and `thread_broadcast` are real messages and must not be caught here.
        if (EventTypeEnum::isCorrection((string) ($event['subtype'] ?? ''))) {
            return false;
        }

        if (in_array($event['channel_type'] ?? '', ['im', 'mpim'], true)) {
            return true;
        }

        if (($event['type'] ?? '') === EventTypeEnum::APP_MENTION->value) {
            return true;
        }

        $botUserId = (string) ($this->receiver->configuration[ConfigurationEnum::BOT_USER_ID->value] ?? '');

        return $botUserId !== '' && str_contains((string) ($event['text'] ?? ''), '<@' . $botUserId . '>');
    }

    /**
     * The invite is the consent to read the room, and the moment the bot can finally see its past.
     */
    private function onMemberJoined(array $event): array
    {
        $botUserId = (string) ($this->receiver->configuration[ConfigurationEnum::BOT_USER_ID->value] ?? '');

        if ($botUserId === '' || ($event['user'] ?? '') !== $botUserId) {
            return ['message' => 'Someone else joined, nothing to do'];
        }

        $slackChannelId = (string) ($event['channel'] ?? '');

        if ($slackChannelId === '' || $this->isDenied($slackChannelId)) {
            return ['message' => 'No channel to backfill'];
        }

        return [
            'message' => $this->scheduleBackfill($slackChannelId) ? 'History backfill queued' : 'History already pulled',
        ];
    }

    /**
     * A room that starts delivering messages without a join event — the bot was already in it when
     * the events were subscribed — gets its history on the first message instead.
     */
    private function ensureBackfillScheduled(array $event, string $slackChannelId): void
    {
        if (in_array($event['channel_type'] ?? '', ['im', 'mpim'], true)) {
            return;
        }

        $this->scheduleBackfill($slackChannelId);
    }

    private function scheduleBackfill(string $slackChannelId): bool
    {
        $done = (array) ($this->receiver->configuration[ConfigurationEnum::CHANNELS_BACKFILLED->value] ?? []);

        if (in_array($slackChannelId, $done, true)) {
            return false;
        }

        return BackfillSlackChannelHistoryJob::dispatchIfIdle($this->receiver, $slackChannelId);
    }
}
