<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersLists\Repositories;

use Kanvas\Social\UsersLists\Models\UserList as ModelUserList;
use Kanvas\Users\Models\Users;

class UserListRepository
{
    public static function getById(int $id, ?Users $user = null): ModelUserList
    {
        $userList = ModelUserList::where('id', $id);
        if ($user) {
            $userList->where('users_id', $user->getId());
        }

        return $userList->firstOrFail();
    }
}
