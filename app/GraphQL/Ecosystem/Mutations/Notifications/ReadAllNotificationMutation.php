<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Notifications;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Actions\ReadAllNotificationAction;

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
            $app = app(Apps::class);
            $action = new ReadAllNotificationAction(auth()->user(), $app);
            $action->execute();

            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
