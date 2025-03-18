<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\UsersLists;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
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

    public function addEntityToList(mixed $rootValue, array $req): bool
    {
        $userList = UserListRepository::getById((int)$req['entity']['users_lists_id'], auth()->user());
        $entity = $this->getEntity($req['entity']['entity_type'], (int)$req['entity']['entity_id']);
        $userList->entities()->create([
            'entity_id' => $entity->id,
            'entity_namespace' => $entity->getMorphClass(),
            'description' => "",
            'is_pin' => false
        ]);
        return true;
    }

    public function removeEntityFromList(mixed $rootValue, array $req): bool
    {
        $userList = UserListRepository::getById((int)$req['entity']['users_lists_id'], auth()->user());
        $entity = $this->getEntity($req['entity']['entity_type'], (int)$req['entity']['entity_id']);

        $userList->entities()->where('entity_id', $entity->id)->delete();

        return true;
    }

    private function getEntity(string $entityType, int $entityId): Model
    {
        $entities = [
            'message' => Message::class,
            'product' => Products::class,
        ];

        if (! isset($entities[$entityType])) {
            throw new \Exception('Invalid entity type');
        }

        return $entities[$entityType]::getById($entityId);
    }
}
