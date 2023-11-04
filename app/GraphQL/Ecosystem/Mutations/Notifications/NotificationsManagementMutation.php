<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Notifications;

use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Actions\EvaluateNotificationsLogicAction;
use Kanvas\Notifications\Jobs\PushNotificationsHandlerJob;
use Kanvas\Notifications\Repositories\NotificationTypesMessageLogicRepository;
use Kanvas\Notifications\Repositories\NotificationTypesRepository;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class NotificationsManagementMutation
{
    /**
     * sendNotificationBaseOnTemplate
     */
    public function sendNotificationBaseOnTemplate(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        if ($user->isAn(RolesEnums::OWNER->value)) {
            $userToNotify = UsersRepository::findUsersByIds($request['users_id']);
        } else {
            $userToNotify = UsersRepository::findUsersByIds($request['users_id'], $company);
            //$userToNotify = UsersRepository::getUserOfCompanyById($user->getCurrentCompany(), $request['users_id']);
        }

        $notification = new Blank(
            $request['template_name'],
            is_string($request['data']) ? json_decode($request['data']) : $request['data'], // This can have more validation like validate if is array o json
            $request['via'],
            $user
        );

        $notification->setFromUser($user);
        Notification::send($userToNotify, $notification);

        return true;
    }

    /**
     * sendNotificationBaseOnTemplate
     */
    public function sendNotificationByMessage(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $message = $request['message'];
        $messageJson = json_decode(json_encode($request['message']));

        $messageType = MessagesTypesRepository::getByVerb($message['metadata']['verb']);
        $noticationTypeMessageLogic = NotificationTypesMessageLogicRepository::getByMessageType($app, $messageType->getId());

        $evaluateNotificationsLogic = new EvaluateNotificationsLogicAction($noticationTypeMessageLogic, $messageJson);
        $results = $evaluateNotificationsLogic->execute();

        //Just for now use a user from the database, later it should be a the logged in user
        $LoggedUser = Users::getById(2);

        if ($results) {
            $followers = UsersFollowsRepository::getFollowersBuilder($LoggedUser)->get();

            foreach ($followers as $follower) {
                foreach ($message['metadata']['channels'] as $channel) {
                    switch ($channel) {
                        case 'push':
                            PushNotificationsHandlerJob::dispatch($follower, $message);

                            break;
                        case 'mail':

                            $notificationType = NotificationTypesRepository::getTemplateByVerbAndEvent($message['metadata']['verb'], $message['metadata']['event'], $app);
                            $user = Users::getById($follower->getOriginal()['id']);

                            $data = [
                                'body' => 'HELLLOOOOOOOOOOO',
                            ];

                            // $notification->setFromUser(auth()->user());
                            $user->notify(new Blank(
                                $notificationType->template()->firstOrFail()->name,
                                $data,
                                ['mail'],
                                $user
                            ));

                            break;
                        default:
                            # code...
                            break;
                    }
                }
            }

            return true;
        }

        return false;
    }
}
