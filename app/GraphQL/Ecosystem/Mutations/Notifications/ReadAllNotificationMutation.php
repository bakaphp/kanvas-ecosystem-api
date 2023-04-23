<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Notifications;

use Exception;
use Kanvas\Notifications\Actions\ReadAllNotification as ReadAllNotificationAction;

final class ReadAllNotificationMutation
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
