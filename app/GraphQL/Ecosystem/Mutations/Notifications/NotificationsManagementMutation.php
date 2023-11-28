<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Notifications;

use Baka\Support\Str;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Actions\EvaluateNotificationsLogicAction;
use Kanvas\Notifications\Actions\SendMessageNotificationsToAllFollowersAction;
use Kanvas\Notifications\Actions\SendMessageNotificationsToOneFollowerAction;
use Kanvas\Notifications\Repositories\NotificationTypesMessageLogicRepository;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
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
    public function sendNotificationByMessage(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $message = $request['message'];

        // TODO Maybe get rid of the notification_type_id on notification_types_message_logic table, not doing anything there?
        $messageType = MessagesTypesRepository::getByVerb($message['metadata']['verb'], $app);
        $notificationTypeMessageLogic = NotificationTypesMessageLogicRepository::getByMessageType($app, $messageType->getId());
        $evaluateNotificationsLogic = new EvaluateNotificationsLogicAction($notificationTypeMessageLogic, $message);
        $results = $evaluateNotificationsLogic->execute();

        if ($results) {
            if ($message['metadata']['distribution']['type'] == 'one' && $follower = Users::getById($message['metadata']['distribution']['userId'])) {
                $sendNotificationsToFollower = new SendMessageNotificationsToOneFollowerAction(
                    $user,
                    $follower,
                    $app,
                    $message
                );
                $sendNotificationsToFollower->execute();

                return true;
            }

            $sendNotificationsToFollowers = new SendMessageNotificationsToAllFollowersAction(
                $user,
                $app,
                $message
            );
            $sendNotificationsToFollowers->execute();

            return true;
        }

        return false;
    }
}
