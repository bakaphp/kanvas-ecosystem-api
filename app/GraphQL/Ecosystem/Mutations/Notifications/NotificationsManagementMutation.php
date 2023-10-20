<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Notifications;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Jobs\NotificationsHandlerJob;
use Kanvas\Notifications\Repositories\NotificationTypesMessageLogicRepository;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\Users\Repositories\UsersRepository;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class NotificationsManagementMutation
{
    /**
     * sendNotificationBaseOnTemplate
     */
    public function sendNotificationBaseOnTemplate(mixed $root, array $request): bool
    {
        $users = UsersRepository::findUsersByIds($request['users_id']);
        $notification = new Blank(
            $request['template_name'],
            is_string($request['data']) ? json_decode($request['data']) : $request['data'], // This can have more validation like validate if is array o json
            $request['via'],
            auth()->user()
        );
        $notification->setFromUser(auth()->user());

        Notification::send($users, $notification);

        return true;
    }

    /**
     * sendNotificationBaseOnTemplate
     */
    public function sendNotificationByMessage(mixed $root, array $request): bool
    {
        $message = $request['message'];
        $messageJson = json_decode(json_encode($request['message']));
        $expressionLanguage = new ExpressionLanguage();


        //Get Message Type by verb
        $messageType = MessagesTypesRepository::getByVerb($message['metadata']['verb']);
        $app = app(Apps::class);

        //Get message logic witb message_type id
        $noticationTypeMessageLogic = NotificationTypesMessageLogicRepository::getByMessageType($app, $messageType->getId());

        $logic = json_decode($noticationTypeMessageLogic->logic);
        $conditions = $logic->conditions;

        $dateInTenMins = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $dateNow = date('Y-m-d H:i:s');

        $results = $expressionLanguage->evaluate(
            $conditions,
            [
                'message' => $messageJson,
                'creationDate' => $dateInTenMins,
            ]
        );


        /**
         * We need to know the event that happened to that entity.
         * By knowing the event and verb we can determine the template information for both email and push notifications?
         * Or, maybe for push notifications the content is on the message and for emails it's on the email_temaplates table
         */



        if ($results) {
            /**
             * @todo This could be solved with the Notifications Channel Enum?
             */
            switch ($message['metadata']['channel']) {
                case 'push':
                    NotificationsHandlerJob::dispatch($message);

                    break;
                case 'mail':

                    //Get email template and send mail

                    $data = [
                        "name" => "John"
                    ];

                    $users = UsersRepository::findUsersByIds([2]);
                    $notification = new Blank(
                        'default',
                        $data,
                        [$message['metadata']['channel']],
                        auth()->user()
                    );
                    $notification->setFromUser(auth()->user());

                    Notification::send($users, $notification);


                    break;
                case 'realtime':
                    break;
                default:
                    # code...
                    break;
            }
        }

        return true;
    }
}
