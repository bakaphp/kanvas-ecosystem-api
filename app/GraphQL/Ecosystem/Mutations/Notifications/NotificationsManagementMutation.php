<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Notifications;

use Illuminate\Support\Facades\Notification;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;

class NotificationsManagementMutation
{
    /**
     * sendNotificationBaseOnTemplate
     */
    public function sendNotificationBaseOnTemplate(mixed $root, array $request)
    {
        $users = Users::find($request['users_id']); //Maybe you need to use the repository to get the users by apps_id
        $notification = new Blank(
            $request['template_name'],
            json_decode($request['data']),
            $request['via']
        );
        Notification::send($users, $notification);
    }
}
