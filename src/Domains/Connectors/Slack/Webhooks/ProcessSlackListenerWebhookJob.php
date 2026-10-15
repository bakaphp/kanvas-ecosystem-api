<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Webhooks;

use Kanvas\Connectors\Slack\Actions\CreateMessageFromSlackListenerEventAction;
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

        if (EventTypeEnum::isNoise($subtype)) {
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
}
