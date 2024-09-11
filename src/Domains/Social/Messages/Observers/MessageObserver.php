<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Observers;

use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;

class MessageObserver
{
    public function created(Message $message): void
    {
        $message->fireWorkflow(WorkflowEnum::CREATED->value, true, ['app' => $message->app]);
        $message->clearLightHouseCacheJob();

        // check if it has a parent, update parent total children
        if ($message->parent_id) {
            $message->parent->total_children += 1;
            $message->parent->save();
        }
    }

    public function updated(Message $message): void
    {
        $message->fireWorkflow(WorkflowEnum::UPDATED->value, true, ['app' => $message->app]);
        $message->clearLightHouseCacheJob();
    }
}
