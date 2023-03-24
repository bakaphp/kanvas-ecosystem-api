<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Notifications;

use Illuminate\Support\Facades\Notification;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Throwable;

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
            is_string($request['data']) ? json_decode($request['data']) : $request['data'], // This can have more validation like validate if is array o json
            $request['via']
        );
        $notification->setFromUser(auth()->user());
        try {
            Notification::send($users, $notification);
            return true;
        } catch (Throwable $e) {
            dd([
                $e->getMessage(),
                $e->getTraceAsString()
            ]);

            return false;
        }
    }
}
