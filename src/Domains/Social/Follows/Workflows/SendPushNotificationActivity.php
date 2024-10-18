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

class SendPushNotificationActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 10;

    //    public function execute(Message $message, AppInterface $app, array $params): array

    public function execute(Model $entity, AppInterface $app, array $params = []): array
    {
        $user = Users::getById($params['message']->users_id);
        $notificationType = NotificationTypesRepository::getByName($params['notification_name'], $app);
        // $notificationType = NotificationTypes::getById(75, $app); //New message notification type for a specific app?

        $messageMetadata = new MessagesNotificationMetadata(
            $notificationType->getId(),
            "FOLLOWERS",
            $params['message']->toArray(),
        );

        SendMessageNotificationsToAllFollowersJob::dispatch(
            $user,
            $app,
            $notificationType,
            $messageMetadata
        );

        Log::info("Push Notifications Sent");

        return [];
    }
}
