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

    public function getUsersListsFollowers(mixed $rootValue, array $req): Collection
    {
        $userList = UserListRepository::getById($req['user_list_id']);
        $followers = $userList->followers()->get();

        return $followers;
    }

    public function isFollowingList(mixed $rootValue, array $req): bool
    {
        $userList = UserListRepository::getById($req['user_list_id']);
        $isFollowing = $userList->followers()->where('users.id', auth()->user()->id)->first();

        return (bool) $isFollowing;
    }

    public function hasUserListItem(mixed $rootValue, array $req): bool
    {
        $userList = UserListRepository::getById($req['user_list_id']);

        return (bool) $userList->items()->where('messages.id', $req['message_id'])->count();
    }
}
