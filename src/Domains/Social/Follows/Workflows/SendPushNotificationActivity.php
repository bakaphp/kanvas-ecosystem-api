<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Workflows;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Notifications\Jobs\SendMessageNotificationsToAllFollowersJob;
use Kanvas\Social\Messages\DataTransferObject\MessagesNotificationMetadata;
use Illuminate\Support\Facades\Log;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Notifications\Repositories\NotificationTypesRepository;
use Kanvas\Notifications\Jobs\SendMessageNotificationsToUsersJob;
use Kanvas\Notifications\Enums\NotificationChannelEnum;

class SendPushNotificationActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 10;

    //    public function execute(Message $message, AppInterface $app, array $params): array

    public function execute(Model $entity, AppInterface $app, array $params = []): array
    {
        $user = Users::getById($entity->users_id);
        $notificationType = NotificationTypesRepository::getByName($params['notification_name'], $app);
        
        // if (!in_array(NotificationChannelEnum::PUSH->value,$notificationType->getChannelsInNotificationFormat())) {
        //     return [
        //         'result' => false,
        //         'message' => 'NotificationType does not have push notification enabled',
        //         'notificationType' => $notificationType->name
        //     ];
        // }

        $messageMetadata = new MessagesNotificationMetadata(
            $notificationType->getId(),
            $entity->toArray(),
            $params['toUser'] ?? [],
            $params['toUser'] ? 'users' : 'followers'
        );

        if ($messageMetadata->distributeToSpecificUsers()) {
            SendMessageNotificationsToUsersJob::dispatch(
                $user,
                $app,
                $notificationType,
                $messageMetadata
            );

            return [
                'result' => true,
                'message' => 'Push Notification sent successfully',
                'data' => $params,
                'entity' => [
                    get_class($entity),
                    $entity->getId(),
                ],
            ];
        }

        SendMessageNotificationsToAllFollowersJob::dispatch(
            $user,
            $app,
            $notificationType,
            $messageMetadata
        );

        return [
            'result' => true,
            'message' => 'Push Notification sent successfully',
            'data' => $params,
            'entity' => [
                get_class($entity),
                $entity->getId(),
            ],
        ];
    }
}
