<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Observers;

use Kanvas\Social\Messages\Actions\CheckMessagePostLimitAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Validations\MessageSchemaValidator;
use Kanvas\Workflow\Enums\WorkflowEnum;

class MessageObserver
{
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

        if ($message->app->get('validate-message-schema')) {
            $checkJson = new MessageSchemaValidator($message, $message->messageType);
            $checkJson->validate();
        }
    }

    public function created(Message $message): void
    {
        if ($message->messageType->verb === $message->app->get('index_message_by_type')) {
            $message->searchable();
        }

        if ($message->parent_id && $message->parent) {
            $message->parent->increment('total_children');
        }


        $message->clearLightHouseCacheJob();

        $message->user->getAppProfile($message->app)->increment('total_messages_count');
    }

    public function updated(Message $message): void
    {
        $message->fireWorkflow(WorkflowEnum::UPDATED->value, true, ['app' => $message->app]);
        $message->clearLightHouseCacheJob();

        if ($message->messageType->verb === $message->app->get('index_message_by_type')) {
            $message->searchable();
        }
    }
}
