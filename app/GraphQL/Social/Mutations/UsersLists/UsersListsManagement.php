<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\UsersLists;

use Kanvas\Apps\Models\Apps;
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
            $req['input']['is_default']
        );

        $createUserList = new CreateUserListAction($userList);

        return $createUserList->execute();
    }

    public function update(mixed $rootValue, array $req): ModelUserList
    {
        $userList = UserListRepository::getById((int)$req['id'], auth()->user());

        $userList->update($req['input']);

        return $userList;
    }

    /**
     * delete
     */
    public function delete(mixed $rootValue, array $req): bool
    {
        $userList = UserListRepository::getById((int)$req['id'], auth()->user());

        return $userList->delete();
    }

    public function addToList(mixed $rootValue, array $req): bool
    {
        $userList = UserListRepository::getById((int)$req['users_lists_id'], auth()->user());
        $message = Message::getById((int)$req['messages_id']);
        $userList->items()->attach($message);

        return true;
    }

    public function removeFromList(mixed $rootValue, array $req): bool
    {
        $userList = UserListRepository::getById((int)$req['users_lists_id'], auth()->user());
        $message = Message::getById((int)$req['messages_id']);

        $userList->items()->detach($message);

        return true;
    }
}
