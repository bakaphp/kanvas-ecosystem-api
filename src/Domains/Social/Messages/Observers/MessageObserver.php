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
    }

    public function updated(Message $message): void
    {
        $message->fireWorkflow(WorkflowEnum::UPDATED->value, true, ['app' => $message->app]);
    }
}
