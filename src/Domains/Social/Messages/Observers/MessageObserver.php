<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Observers;

use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Intelligence\Agents\Services\AgentChannelActivity;
use Kanvas\Social\Channels\Events\ChannelMessageChangedEvent;
use Kanvas\Social\Channels\Events\ChannelMessageCreatedEvent;
use Kanvas\Social\Channels\Events\ChannelMessageRemovedEvent;
use Kanvas\Social\Channels\Events\ChannelMessageUpdatedEvent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CheckMessagePostLimitAction;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;
use Kanvas\Social\Messages\Jobs\ProcessMessageMentionsJob;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Validations\MessageSchemaValidator;
use Kanvas\Workflow\Enums\WorkflowEnum;

class MessageObserver
{
    public function saving(Message $message): void
    {
        $payload = $message->message;
        $message->sender_type = is_array($payload)
            ? MessageSenderTypeEnum::fromPayload($payload)?->value
            : null;
    }

    public function creating(Message $message): void
    {
        //$messageData = is_array($message->message) ? $message->message : json_decode($message->message, true);
        /* if (
            $message->app->get('message-image-type')
            && is_array($message->message)
            && isset($message->message['type'])
            && $message->message['type'] === 'image-format'
            && $message->messageType->verb == $message->app->get('image-generation-limit-message-type-verb')
        ) {
            (new CheckMessagePostLimitAction(
                message: $message,
                messageTypeId: $message->message_types_id
            ))->execute();
        } */

        if ($message->app->get('validate-message-schema') && $message->messageType !== null) {
            $checkJson = new MessageSchemaValidator($message, $message->messageType);
            $checkJson->validate();
        }
    }

    public function created(Message $message): void
    {
        if ($message->isIndexedMessageType()) {
            $message->searchable();
        }

        if ($message->parent_id && $message->parent) {
            $message->parent->increment('total_children');
        }

        $message->clearLightHouseCacheJob();

        $message->user->getAppProfile($message->app)->increment('total_messages_count');

        // Resolve @mentions off the hot path — including agent (from_ia) messages, so an agent can
        // @mention a human to notify them. Agent-to-agent hops are bounded by the budget in
        // RespondToAgentMentionListener. The agent's own compaction note is the one exception: it
        // quotes the conversation, handles included, so parsing it pings everyone named and wakes
        // the agent that wrote it with its own summary, which summarizes and posts again.
        if (AgentChannelActivity::isCompactionNote($message)) {
            return;
        }

        if (str_contains($message->contentText(), '@')) {
            ProcessMessageMentionsJob::dispatch($message);
        }
    }

    public function updated(Message $message): void
    {
        $message->fireWorkflow(WorkflowEnum::UPDATED->value, true, ['app' => $message->app]);
        $message->clearLightHouseCacheJob();

        if ($message->isIndexedMessageType()) {
            $message->searchableSync();
        }

        // Notes only: servers rewrite `message` on conversation messages constantly (delivery
        // statuses, OFAC, CRM pushes) and each event makes every open client re-read.
        if ($message->wasChanged('message') && $this->isBroadcastableNote($message)) {
            $this->broadcastChange($message, ChannelMessageUpdatedEvent::class);
        }
    }

    public function restored(Message $message): void
    {
        if (! $this->isBroadcastableNote($message)) {
            return;
        }

        /** @var Channel $channel */
        foreach ($message->channels as $channel) {
            ChannelMessageCreatedEvent::dispatch($channel, $message);
        }
    }

    public function deleted(Message $message): void
    {
        if ($this->isBroadcastableNote($message)) {
            $this->broadcastChange($message, ChannelMessageRemovedEvent::class);
        }

        /** @var Channel $channel */
        foreach ($message->channels as $channel) {
            if ((int) $channel->last_message_id !== $message->id) {
                continue;
            }

            $channel->last_message_id = $channel->getPreviousMessage($message)?->id;
            $channel->saveOrFail();
        }

        if ($message->isIndexedMessageType()) {
            $message->unsearchable();
        }
    }

    private function isBroadcastableNote(Message $message): bool
    {
        return $message->isPublic()
            && ! $message->isLocked()
            && $message->messageType?->verb === LeadMessageTypeEnum::NOTES->value;
    }

    /**
     * @param class-string<ChannelMessageChangedEvent> $event
     */
    private function broadcastChange(Message $message, string $event): void
    {
        /** @var Channel $channel */
        foreach ($message->channels as $channel) {
            $event::dispatch(
                $message->id,
                $message->slug,
                $channel->id,
                $channel->slug,
                $channel->apps_id,
                $channel->entity_namespace,
                $channel->entity_id,
            );
        }
    }
}
