<?php

declare(strict_types=1);

namespace Kanvas\Users\Services;

use Baka\Contracts\AppInterface;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Notifications\Notification;
use Kanvas\Users\Repositories\UserRoleRepository;

class UserRoleNotificationService
{
    public static function sendNotification(
        string $role,
        Notification $notification,
        AppInterface $app
    ): void {
        $role = RolesRepository::getByNameFromCompany(
            name: $role,
            app: $app
        );
        UserRoleRepository::getAllUsersOfRole($role, $app)
            ->chunk(100, function ($users) use ($notification) {
                foreach ($users as $user) {
                    $user->notify($notification);
                }
            });
    }
}
