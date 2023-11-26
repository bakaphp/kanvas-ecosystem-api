<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersLists\Observers;

use Kanvas\Social\UsersLists\Models\UserList;

class UsersListsObserver
{
    public function saved(UserList $usersLists): void
    {
        $defaultUsersLists = UserList::where('is_default', 1)
                    ->where('id', '!=', $usersLists->getId())
                    ->where('users_id', $usersLists->users_id)
                    ->first();
        if ($defaultUsersLists) {
            $defaultUsersLists->is_default = 0;
            $defaultUsersLists->saveQuietly();
        }
    }
}
