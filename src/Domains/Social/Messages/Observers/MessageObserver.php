<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Observers;

use Kanvas\Connectors\PromptMine\Actions\CheckNuggetGenerationCountAction;
use Kanvas\Social\Messages\Actions\CheckMessagePostLimitAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Validations\MessageSchemaValidator;
use Kanvas\Workflow\Enums\WorkflowEnum;

class MessageObserver
{
    public function creating(Message $message): void
    {
        //$messageData = is_array($message->message) ? $message->message : json_decode($message->message, true);
        if ($message->app->get('message-image-type') && is_array($message->message) && isset($message->message['type']) && $message->message['type'] === 'image-format') {
            (new CheckMessagePostLimitAction(
                message: $message,
                getChildrenCount: true
            ))->execute();
        }

        if ($message->app->get('validate-message-schema')) {
            $checkJson = new MessageSchemaValidator($message, $message->messageType);
            $checkJson->validate();
        }
    }

    public function saved(Message $message): void
    {
        // check if it has a parent, update parent total children
        if ($message->parent_id && $message->parent) {
            $message->parent->increment('total_children');
            $message->parent->searchable();
        }
    }

    public function created(Message $message): void
    {
        /*         if ($message->app->get('check-free-generation-count') && $message->app->get('free-generation-check-message-type') && $message->parent_id) {
                    (new CheckNuggetGenerationCountAction($message))->execute();
                } */

        $message->clearLightHouseCacheJob();
    }

    public function updated(Message $message): void
    {
        $message->fireWorkflow(WorkflowEnum::UPDATED->value, true, ['app' => $message->app]);
        $message->clearLightHouseCacheJob();
    }
}
