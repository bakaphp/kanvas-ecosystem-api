<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Notifications;

use Baka\Support\Str;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Actions\EvaluateNotificationsLogicAction;
use Kanvas\Notifications\Jobs\SendMessageNotificationsToAllFollowersJob;
use Kanvas\Notifications\Jobs\SendMessageNotificationsToUsersJob;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Repositories\NotificationTypesMessageLogicRepository;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\DataTransferObject\MessagesNotificationMetadata;
use Kanvas\Users\Repositories\UsersRepository;

class NotificationsManagementMutation
{
    /**
     * sendNotificationBaseOnTemplate
     * @deprecated use sendNotificationByMessage
     * @psalm-suppress MixedArgument
     */
    public function sendNotificationBaseOnTemplate(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        if ($user->isAdmin()) {
            $userToNotify = UsersRepository::findUsersByArray($request['users']);
        } else {
            $userToNotify = UsersRepository::findUsersByArray($request['users'], $company);
        }

        $data = Str::isJson($request['data']) ? json_decode($request['data'], true) : (array) $request['data']; // This can have more validation like validate if is array o json
        $data['app'] = app(Apps::class);

        $notification = new Blank(
            $request['template_name'],
            $data,
            $request['via'],
            $user,
            key_exists('attachment', $request) ? $request['attachment'] : null
        );

        $notification->setFromUser($user);
        Notification::send($userToNotify, $notification);

        return true;
    }

    public function anonymousNotification(mixed $root, array $request)
    {
        $data = Str::isJson($request['data']) ? json_decode($request['data'], true) : (array) $request['data'];
        $data['app'] = app(Apps::class);
        $user = auth()->user();

        $notification = new Blank(
            $request['template_name'],
            $data,
            ['mail'],
            $user
        );
        $notification->setFromUser($user);
        $notification->setSubject($request['subject']);
        Notification::route('mail', $request['email'])->notify($notification);

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
        $notificationType = NotificationTypes::getById($notificationMessagePayload->notificationTypeId, $app);
        $notificationTypeMessageLogic = NotificationTypesMessageLogicRepository::getByNotificationType($app, $notificationType);

        if (! $notificationType) {
            return [
                'sent' => false,
                'message' => 'Notification type not found',
            ];
        }

        $canSendNotification = true;

        if ($notificationTypeMessageLogic) {
            $canSendNotification = (new EvaluateNotificationsLogicAction(
                $app,
                $user,
                $notificationTypeMessageLogic,
                $notificationMessagePayload->message
            ))->execute();
        }

        if (! $canSendNotification) {
            return [
                'sent' => false,
                'message' => 'Notification logic not met',
            ];
        }

        if ($notificationMessagePayload->distributeToSpecificUsers()) {
            SendMessageNotificationsToUsersJob::dispatch(
                $user,
                $app,
                $notificationType,
                $notificationMessagePayload
            );

            return [
                'sent' => true,
                'message' => 'Notification sent to users ' . implode(',', $notificationMessagePayload->usersId),
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
