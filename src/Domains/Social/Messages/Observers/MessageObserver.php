<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Observers;

use Kanvas\Social\Messages\Actions\CheckMessagePostLimitAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;
use NetSuite\Classes\Check;

class MessageObserver
{
    public function creating(Message $message)
    {
        if ($message->app->get('message-image-type')) {
            (new CheckMessagePostLimitAction(
                message: $message,
                getChildrenCount: true
            ))->execute();
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
