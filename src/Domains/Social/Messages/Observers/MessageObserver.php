<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Observers;

use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Notifications\Jobs\SendMessageNotificationsToAllFollowersJob;
use Kanvas\Social\Messages\DataTransferObject\MessagesNotificationMetadata;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Repositories\NotificationTypesMessageLogicRepository;

class MessageObserver
{
    public function created(Message $message): void
    {
        $message->fireWorkflow(WorkflowEnum::CREATED->value, true, ['app' => $message->app]);
        $message->clearLightHouseCacheJob();

        // check if it has a parent, update parent total children
        if ($message->parent_id) {
            $message->parent->increment('total_children');
        }

        if ($message->app == 78) {


            //Get the user from the message
            $user = Users::getById($message->users_id);
            $app = app(Apps::class);
            $notificationType = NotificationTypes::getById(75, $app);
            $notificationTypeMessageLogic = NotificationTypesMessageLogicRepository::getByNotificationType($app, $notificationType);

            $messageMetadata = new MessagesNotificationMetadata(
                $notificationType->getId(),
                "FOLLOWERS",
                $message,
            );

            SendMessageNotificationsToAllFollowersJob::dispatch(
                $user,
                $app,
                $notificationType,
                $messageMetadata
            );
        }
    }

    public function updated(Message $message): void
    {
        $message->fireWorkflow(WorkflowEnum::UPDATED->value, true, ['app' => $message->app]);
        $message->clearLightHouseCacheJob();
    }
}
