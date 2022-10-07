<?php
namespace App\GraphQL\Ecosystem\Mutations\Notifications;

use Kanvas\Notifications\Actions\ReadAllNotification as ReadAllNotificationAction;
use Exception;

final class ReadAllNotification
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $args)
    {
        // TODO implement the resolver
        try {
            $action = new ReadAllNotificationAction(auth()->user());
            $action->execute();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
