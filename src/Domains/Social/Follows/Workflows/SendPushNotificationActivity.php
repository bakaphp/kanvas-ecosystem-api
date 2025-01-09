<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Workflows;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Jobs\SendMessageNotificationsToAllFollowersJob;
use Kanvas\Notifications\Jobs\SendMessageNotificationsToUsersJob;
use Kanvas\Notifications\Repositories\NotificationTypesRepository;
use Kanvas\Social\Messages\DataTransferObject\MessagesNotificationMetadata;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class SendPushNotificationActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 3;

    public function execute(Model $entity, AppInterface $app, array $params = []): array
    {
        $user = Users::getById($entity->users_id);
        $notificationType = NotificationTypesRepository::getByName($params['notification_name'], $app);
        $toUsersArray = $params['toUsers'] ?? [];
        $distributionType = isset($params['toUsers']) && count($params['toUsers']) > 0 ? 'users' : 'followers';

        if (! in_array(NotificationChannelEnum::PUSH->value, $notificationType->getChannelsInNotificationFormat())) {
            return [
                'result' => false,
                'message' => 'NotificationType does not have push notification enabled',
                'notificationType' => $notificationType->name,
            ];
        }

        $messageMetadata = new MessagesNotificationMetadata(
            $notificationType->getId(),
            $distributionType,
            $entity->toArray(),
            $toUsersArray
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
