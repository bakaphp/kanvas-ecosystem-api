<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Observers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Exception;

class MessageObserver
{
    public function creating(Message $message)
    {
        $app = app(Apps::class);
        $user = $message->user;
        $messageData = json_decode($message->message, true);


        if (array_key_exists('type', $messageData) && $messageData['type'] == 'image-format') {
            $messageCount = Message::getUserMessageCountInTimeFrame(
                $user->getId(),
                $app,
                24,
                null,
                true
            );
    
            if ($messageCount >= 5) {
                throw new Exception('You have reached the limit of messages you can post in a day');
            }
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
