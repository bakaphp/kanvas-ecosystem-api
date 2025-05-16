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
        if ($message->app->get('message-image-type') && is_array($message->message) && $message->message['type'] === 'image-format') {
            (new CheckMessagePostLimitAction(
                message: $message,
                getChildrenCount: true
            ))->execute();
        }

        if ($message->app->get('validate-message-schema')) {
            $checkJson = new MessageSchemaValidator($message, $message->messageType);
            $checkJson->validate();
        }

        if ($message->app->get('check-free-generation-count')) {
            //(new CheckNuggetGenerationCountAction($message))->execute();
        }
    }

    public function created(Message $message): void
    {
        /*         $message->fireWorkflow(WorkflowEnum::CREATED->value, true, [
                    'app' => $message->app,
                    'notification_name' => WorkflowEnum::CREATED->value . '-' . $message->messageType->name
                ]); */

        $message->clearLightHouseCacheJob();

        // check if it has a parent, update parent total children
        if ($message->parent_id) {
            $message->parent->increment('total_children');
        }
    }

    public function updated(Message $message): void
    {
        $message->fireWorkflow(WorkflowEnum::UPDATED->value, true, ['app' => $message->app]);
        $message->clearLightHouseCacheJob();
    }
}
