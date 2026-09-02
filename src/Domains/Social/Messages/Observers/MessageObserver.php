<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Observers;

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
        // @mention a human to notify them. RespondToAgentMentionListener skips from_ia so an agent's
        // own reply still never wakes another agent (the anti-loop guard lives there now).
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
    }

    public function deleted(Message $message): void
    {
        // Check each channel this message belongs to
        foreach ($message->channels as $channel) {
            $remainingMessagesCount = $channel->messages()
                ->where('messages.id', '!=', $message->id)
                ->count();

            if ($remainingMessagesCount === 0) {
                $channel->is_deleted = 1;
                $channel->last_message_id = null;
                $channel->saveOrFail();
            } elseif ($channel->last_message_id === $message->id) {
                $previousMessage = $channel->getPreviousMessage($message);
                $channel->last_message_id = $previousMessage?->id;
                $channel->saveOrFail();
            }
        }
        if ($message->isIndexedMessageType()) {
            $message->unsearchable();
        }
    }
}
