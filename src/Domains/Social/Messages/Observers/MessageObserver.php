<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Observers;

use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Notifications\Repositories\NotificationTypesRepository;

class MessageObserver
{
    public function created(Message $message): void
    {
        $message->fireWorkflow(WorkflowEnum::CREATED->value, true, [
            'app' => $message->app,
            'notification_name' => WorkflowEnum::CREATED->value . '-' . $message->messageType->name
        ]);

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
