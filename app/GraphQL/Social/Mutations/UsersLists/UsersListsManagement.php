<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\UsersLists;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Actions\FollowAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\UsersLists\Actions\CreateUserListAction;
use Kanvas\Social\UsersLists\DataTransferObject\UserList;
use Kanvas\Social\UsersLists\Models\UserList as ModelUserList;
use Kanvas\Social\UsersLists\Repositories\UserListRepository;

class UsersListsManagement
{
    public function create($rootValue, array $req): ModelUserList
    {
        $userList = new UserList(
            app(Apps::class)->id,
            auth()->user()->getCurrentCompany()->id,
            auth()->user()->id,
            $req['input']['name'],
            $req['input']['description'],
            $req['input']['is_public'],
            $req['input']['is_default'],
            $req['input']['files'] ?? []
        );

        $createUserList = new CreateUserListAction($userList);

        return $createUserList->execute();
    }

    public function update(mixed $rootValue, array $req): ModelUserList
    {
        $userList = UserListRepository::getById($req['id'], auth()->user());

        $userList->update($req['input']);

        return $userList;
    }

    /**
     * delete
     */
    public function delete(mixed $rootValue, array $req): bool
    {
        $userList = UserListRepository::getById($req['id'], auth()->user());

        return $userList->delete();
    }

    public function addToList(mixed $rootValue, array $req): ModelUserList
    {
        $userList = UserListRepository::getById($req['users_lists_id'], auth()->user());
        $message = Message::getById($req['messages_id']);
        $userList->items()->attach($message);

        return $userList;
    }

    public function removeFromList(mixed $rootValue, array $req): ModelUserList
    {
        $userList = UserListRepository::getById($req['users_lists_id'], auth()->user());
        $message = Message::getById($req['messages_id']);

        $userList->items()->detach($message);

        return $userList;
    }

    public function followList(mixed $rootValue, array $req): bool
    {
        $userList = UserListRepository::getById($req['users_lists_id']);
        FollowAction::execute(auth()->user(), $userList);

        return true;
    }

    public function unFollowList(mixed $rootValue, array $req): bool
    {
        $userList = UserListRepository::getById($req['users_lists_id']);
        UnFollowAction::execute(auth()->user(), $userList);

        return true;
    }
}
