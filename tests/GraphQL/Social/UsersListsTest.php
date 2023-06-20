<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Tests\TestCase;
use Kanvas\Social\Messages\Models\Message;

class UsersListsTest extends TestCase
{
    /**
     * testCreateUsersLists
     *
     * @return void
     */
    public function testCreateUsersLists()
    {
        $input =[
            'name' => fake()->name(),
            'description' => fake()->text(),
            'is_public' => fake()->boolean(),
            'is_default' => fake()->boolean(),
        ];
        $this->graphQL(
            '
                mutation createUserList($input: UserListInput!) {
                    createUserList(input: $input) {
                        name,
                        description,
                        is_public,
                        is_default
                    }
                }
            ',
            [
                'input' => $input
            ]
        )->assertJson([
            'data' => [
                'createUserList' => $input
            ],
        ]);
    }

    /**
     * testUpdateUsersLists
     *
     * @return void
     */
    public function testUpdateUsersLists()
    {
        $input =[
            'name' => fake()->name(),
            'description' => fake()->text(),
            'is_public' => fake()->boolean(),
            'is_default' => fake()->boolean(),
        ];
        $response = $this->graphQL(
            '
                mutation createUserList($input: UserListInput!) {
                    createUserList(input: $input) {
                        id
                    }
                }
            ',
            [
                'input' => $input
            ]
        );
        $id = $response->json('data.createUserList.id');
        $newName = fake()->name();
        $this->graphQL(
            '
                mutation updateUserList($id: Int!, $input: UserListInput!) {
                    updateUserList(id: $id, input: $input) {
                        id
                        name
                    }
                }
            ',
            [
                'id' => $id,
                'input' => [
                    'name' => $newName,
                    'description' => fake()->text(),
                    'is_public' => fake()->boolean(),
                    'is_default' => fake()->boolean(),
                ],
            ]
        )->assertJson([
            'data' => [
                'updateUserList' => [
                    'id' => $id,
                    'name' => $newName,
                ],
            ],
        ]);
    }

    /**
     * testDeleteUsersLists
     *
     * @return void
     */
    public function testDeleteUsersLists()
    {
        $input =[
            'name' => fake()->name(),
            'description' => fake()->text(),
            'is_public' => fake()->boolean(),
            'is_default' => fake()->boolean(),
        ];
        $response = $this->graphQL(
            '
                mutation createUserList($input: UserListInput!) {
                    createUserList(input: $input) {
                        id
                    }
                }
            ',
            [
                'input' => $input
            ]
        );
        $id = $response->json('data.createUserList.id');
        $this->graphQL(
            '
                mutation deleteUserList($id: Int!) {
                    deleteUserList(id: $id) 
                }
            ',
            [
                'id' => $id,
            ]
        )->assertJson([
            'data' => [
                'deleteUserList' => true
            ],
        ]);
    }

    public function testAddToList()
    {
        $message = Message::factory()->create();
        $input =[
            'name' => fake()->name(),
            'description' => fake()->text(),
            'is_public' => fake()->boolean(),
            'is_default' => fake()->boolean(),
        ];
        $response = $this->graphQL(
            '
                mutation createUserList($input: UserListInput!) {
                    createUserList(input: $input) {
                        id
                    }
                }
            ',
            [
                'input' => $input
            ]
        );
        $id = $response->json('data.createUserList.id');

        $this->graphQL(
            '
                mutation addToList($users_lists_id: Int!, $messages_id: Int!) {
                    addToList(users_lists_id: $users_lists_id, messages_id: $messages_id) 
                }
            ',
            [
                'users_lists_id' => $id,
                'messages_id' => $message->id,
            ]
        )->assertJson([
            'data' => [
                'addToList' => true
            ],
        ]);
    }

    public function testRemoveFromList()
    {
        $message = Message::factory()->create();
        $input =[
            'name' => fake()->name(),
            'description' => fake()->text(),
            'is_public' => fake()->boolean(),
            'is_default' => fake()->boolean(),
        ];
        $response = $this->graphQL(
            '
                mutation createUserList($input: UserListInput!) {
                    createUserList(input: $input) {
                        id
                    }
                }
            ',
            [
                'input' => $input
            ]
        );
        $id = $response->json('data.createUserList.id');

        $this->graphQL(
            '
                mutation addToList($users_lists_id: Int!, $messages_id: Int!) {
                    addToList(users_lists_id: $users_lists_id, messages_id: $messages_id) 
                }
            ',
            [
                'users_lists_id' => $id,
                'messages_id' => $message->id,
            ]
        )->assertJson([
            'data' => [
                'addToList' => true
            ],
        ]);

        $this->graphQL(
            '
                mutation removeFromList($users_lists_id: Int!, $messages_id: Int!) {
                    removeFromList(users_lists_id: $users_lists_id, messages_id: $messages_id) 
                }
            ',
            [
                'users_lists_id' => $id,
                'messages_id' => $message->id,
            ]
        )->assertJson([
            'data' => [
                'removeFromList' => true
            ],
        ]);
    }
}
