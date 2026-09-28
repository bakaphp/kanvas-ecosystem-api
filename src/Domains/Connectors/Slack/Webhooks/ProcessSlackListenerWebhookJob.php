<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Webhooks;

use Kanvas\Connectors\Slack\Actions\CreateMessageFromSlackListenerEventAction;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Enums\EventTypeEnum;
use Kanvas\Connectors\Slack\Jobs\JoinChannelJob;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Override;

#[WorkflowAction(
    name: 'Slack Workspace Listener',
    description: 'Read-only receiver: records EVERY message from every Slack conversation the listener bot is '
        . 'in, into Kanvas channels and messages. It never replies, never wakes an agent and spends no '
        . 'tokens — use it to build a searchable record of what the team actually said. Do NOT use it '
        . 'when you want an agent to answer in Slack; that is the Slack Agent Webhook.',
)]
class ProcessSlackListenerWebhookJob extends SlackWebhookJob
{
    private const array IGNORED_SUBTYPES = [
        'channel_join',
        'channel_leave',
        'channel_topic',
        'channel_purpose',
        'channel_name',
        'channel_archive',
        'channel_unarchive',
    ];

    /**
     * The only receiver whose volume isn't bounded by customer action — it fires on internal chatter
     * across every channel. On the shared default queue it would sit in front of agent replies.
     */
    public function __construct(ReceiverWebhookCall $webhookRequest)
    {
        parent::__construct($webhookRequest);

        $this->onQueue('slack-ingest');
    }

    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $event = $payload['event'] ?? [];
        $type = (string) ($event['type'] ?? '');

        if (EventTypeEnum::isLifecycle($type)) {
            $this->deactivateReceiver();

            return ['message' => 'Slack app uninstalled, listener deactivated'];
        }

        if (! $this->isFirstDelivery((string) ($payload['event_id'] ?? ''))) {
            return ['message' => 'Duplicate event'];
        }

        if ($type === EventTypeEnum::CHANNEL_CREATED->value) {
            JoinChannelJob::dispatch(
                $this->receiver->app,
                $this->receiver,
                (string) ($event['channel']['id'] ?? ''),
            );

            return ['message' => 'Joining newly created channel'];
        }

        if ($type !== EventTypeEnum::MESSAGE->value) {
            return ['message' => 'Not a message event'];
        }

        if ($this->isFromBot($event)) {
            return ['message' => 'Bot message ignored'];
        }

        $subtype = (string) ($event['subtype'] ?? '');

        if (in_array($subtype, self::IGNORED_SUBTYPES, true)) {
            return ['message' => 'Non-conversational subtype ignored'];
        }

        [$event, $tags] = $this->normalize($event, $subtype);

        if ($this->isDenied((string) ($event['channel'] ?? ''))) {
            return ['message' => 'Channel is on the deny list'];
        }

        $message = new CreateMessageFromSlackListenerEventAction(
            $this->webhookRequest,
            $event,
            $tags,
        )->execute();

        return [
            'message' => $message === null ? 'Empty message skipped' : 'Ingested',
            'entity' => $message?->getId(),
        ];
    }

    /**
     * Edits and deletions nest their real content under `message` / `previous_message`, with the
     * channel only on the wrapper.
     *
     * They become NEW rows rather than mutating the original: finding the original means a JSON
     * lookup on `messages.message->message_id`, which has no index and would table-scan on every
     * edit in a busy workspace. Append-only also keeps the real history.
     *
     * @return array{0: array, 1: list<string>}
     */
    private function normalize(array $event, string $subtype): array
    {
        return match ($subtype) {
            EventTypeEnum::MESSAGE_CHANGED->value => [
                [...(array) ($event['message'] ?? []), 'channel' => $event['channel'] ?? ''],
                ['slack-edit'],
            ],
            EventTypeEnum::MESSAGE_DELETED->value => [
                [...(array) ($event['previous_message'] ?? []), 'channel' => $event['channel'] ?? ''],
                ['slack-delete'],
            ],
            default => [$event, []],
        };
    }

    private function isDenied(string $slackChannelId): bool
    {
        $denyList = $this->receiver->configuration[ConfigurationEnum::CHANNEL_DENY_LIST->value] ?? [];

        return is_array($denyList) && in_array($slackChannelId, $denyList, true);
    }
}
