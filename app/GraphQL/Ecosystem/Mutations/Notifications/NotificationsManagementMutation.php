<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Notifications;

use Baka\Support\Str;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Actions\EvaluateNotificationsLogicAction;
use Kanvas\Notifications\Jobs\SendMessageNotificationsToAllFollowersJob;
use Kanvas\Notifications\Jobs\SendMessageNotificationsToOneFollowerJob;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Repositories\NotificationTypesMessageLogicRepository;
use Kanvas\Notifications\Repositories\NotificationTypesRepository;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\DataTransferObject\MessagesNotificationMetadata;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class NotificationsManagementMutation
{
    /**
     * sendNotificationBaseOnTemplate
     * @psalm-suppress MixedArgument
     */
    public function sendNotificationBaseOnTemplate(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        if ($user->isAn(RolesEnums::OWNER->value)) {
            $userToNotify = UsersRepository::findUsersByIds($request['users_id']);
        } else {
            $userToNotify = UsersRepository::findUsersByIds($request['users_id'], $company);
        }

        $notification = new Blank(
            $request['template_name'],
            Str::isJson($request['data']) ? json_decode($request['data'], true) : (array) $request['data'], // This can have more validation like validate if is array o json
            $request['via'],
            $user
        );

        $notification->setFromUser($user);
        Notification::send($userToNotify, $notification);

        return true;
    }

    /**
     * sendNotificationByMessage
     * @psalm-suppress MixedArgument
     */
    public function sendNotificationByMessage(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $notificationMessagePayload = MessagesNotificationMetadata::fromArray($request);

        // TODO Maybe get rid of the notification_type_id on notification_types_message_logic table, not doing anything there?
        $notificationType = NotificationTypes::getById($notificationMessagePayload->notificationTypeId, $app);
        $notificationTypeMessageLogic = NotificationTypesMessageLogicRepository::getByNotificationType($app, $notificationType);
        //$notificationType = NotificationTypesRepository::getTemplateByVerbAndEvent($app, $notificationMessagePayload->verb, $notificationMessagePayload->event);

        if (! $notificationType) {
            return [
                'sent' => false,
                'message' => 'Notification type not found',
            ];
        }

        $canSendNotification = true;

        if ($notificationTypeMessageLogic) {
            $evaluateNotificationsLogic = new EvaluateNotificationsLogicAction($notificationTypeMessageLogic, $notificationMessagePayload->message);
            $canSendNotification = $evaluateNotificationsLogic->execute();
        }

        if (! $canSendNotification) {
            return [
                'sent' => false,
                'message' => 'Notification logic not met',
            ];
        }

        $sendToOneFollower = $notificationMessagePayload->distributeToOneFollower() && $follower = Users::getById($notificationMessagePayload->followerId);
        if ($sendToOneFollower) {
            SendMessageNotificationsToOneFollowerJob::dispatch(
                $user,
                $follower,
                $app,
                $notificationType,
                $notificationMessagePayload
            );

            return [
                'sent' => true,
                'message' => 'Notification sent to one follower ' . $follower->getId(),
            ];
        }

        if (! $notificationMessagePayload->distributeToFollowers()) {
            return [
                'sent' => false,
                'message' => 'Notification distribution type not found in request payload',
            ];
        }

        SendMessageNotificationsToAllFollowersJob::dispatch(
            $user,
            $app,
            $notificationType,
            $notificationMessagePayload
        );

        return [
            'sent' => true,
            'message' => 'Notification sent to all followers',
        ];
    }
}
