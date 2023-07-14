<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\UsersLists;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Social\UsersLists\Repositories\UserListRepository;

class UsersListsQuery
{
    public function getFileByName(mixed $rootValue, array $req): Collection
    {
        $userList = UserListRepository::getById($req['user_list_id']);
        $files = $userList->files()->where('filesystem_entities.field_name', $req['field_name'])->get();
        return $files;
    }
}
