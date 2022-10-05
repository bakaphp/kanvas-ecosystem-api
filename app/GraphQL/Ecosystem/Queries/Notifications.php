<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Queries;

use Kanvas\Notifications\Models\Notifications as NotificationsModel;

final class Notifications
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $args)
    {
        // TODO implement the resolver
        return NotificationsModel::where('users_id', auth()->user()->id)->get();
    }
}
