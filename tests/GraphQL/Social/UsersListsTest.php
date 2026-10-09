<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\UsersLists\Models\UserList;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\AssertsIsDefaultOrdering;

class UsersListsTest extends TestCase
{
    use AssertsIsDefaultOrdering;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social'];

    /**
     * testCreateUsersLists
     *
     * @return void
     */
    public function testCreateUsersLists()
    {
        $input = [
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
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'createUserList' => $input,
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
        $input = [
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
                'input' => $input,
            ]
        );
        $id = $response->json('data.createUserList.id');
        $newName = fake()->name();
        $this->graphQL(
            '
                mutation updateUserList($id: ID!, $input: UserListInput!) {
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
        $input = [
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
                'input' => $input,
            ]
        );
        $id = $response->json('data.createUserList.id');
        $this->graphQL(
            '
                mutation deleteUserList($id: ID!) {
                    deleteUserList(id: $id) 
                }
            ',
            [
                'id' => $id,
            ]
        )->assertJson([
            'data' => [
                'deleteUserList' => true,
            ],
        ]);
    }

    public function testAddToList()
    {
        $message = Message::factory()->create();
        $input = [
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
                'input' => $input,
            ]
        );
        $id = $response->json('data.createUserList.id');

        $this->graphQL(
            '
                mutation addToUserList($users_lists_id: ID!, $messages_id: ID!) {
                    addToUserList(users_lists_id: $users_lists_id, messages_id: $messages_id) 
                }
            ',
            [
                'users_lists_id' => $id,
                'messages_id' => $message->id,
            ]
        )->assertJson([
            'data' => [
                'addToUserList' => true,
            ],
        ]);
    }

    public function testRemoveFromList()
    {
        $message = Message::factory()->create();
        $input = [
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
                'input' => $input,
            ]
        );
        $id = $response->json('data.createUserList.id');

        $this->graphQL(
            '
                mutation addToUserList($users_lists_id: ID!, $messages_id: ID!) {
                    addToUserList(users_lists_id: $users_lists_id, messages_id: $messages_id) 
                }
            ',
            [
                'users_lists_id' => $id,
                'messages_id' => $message->id,
            ]
        )->assertJson([
            'data' => [
                'addToUserList' => true,
            ],
        ]);

        $this->graphQL(
            '
                mutation removeFromUserList($users_lists_id: ID!, $messages_id: ID!) {
                    removeFromUserList(users_lists_id: $users_lists_id, messages_id: $messages_id) 
                }
            ',
            [
                'users_lists_id' => $id,
                'messages_id' => $message->id,
            ]
        )->assertJson([
            'data' => [
                'removeFromUserList' => true,
            ],
        ]);
    }

    public function testAddEntityToList()
    {
        $message = Message::factory()->create();
        $input = [
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
                'input' => $input,
            ]
        );
        $id = $response->json('data.createUserList.id');

        $this->graphQL(
            '
                mutation addEntityToUserList($entity: EntityInput!) {
                    addEntityToUserList(entity: $entity) 
                }
            ',
            [
                'entity' => [
                    'users_lists_id' => $id,
                    'entity_id' => $message->id,
                    'entity_type' => 'message',
                ],
            ]
        )->assertJson([
            'data' => [
                'addEntityToUserList' => true,
            ],
        ]);
    }

    public function testRemoveEntityFromList()
    {
        $message = Message::factory()->create();
        $input = [
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
                'input' => $input,
            ]
        );
        $id = $response->json('data.createUserList.id');

        $this->graphQL(
            '
                mutation addEntityToUserList($entity: EntityInput!) {
                    addEntityToUserList(entity: $entity) 
                }
            ',
            [
                'entity' => [
                    'users_lists_id' => $id,
                    'entity_id' => $message->id,
                    'entity_type' => 'message',
                ],
            ]
        );

        $this->graphQL(
            '
                mutation removeEntityFromUserList($entity: EntityInput!) {
                    removeEntityFromUserList(entity: $entity) 
                }
            ',
            [
                'entity' => [
                    'users_lists_id' => $id,
                    'entity_id' => $message->id,
                    'entity_type' => 'message',
                ],
            ]
        )->assertJson([
            'data' => [
                'removeEntityFromUserList' => true,
            ],
        ]);
    }

    public static function usersListsQueryProvider(): array
    {
        return [
            'getUsersLists' => ['getUsersLists'],
            'getUsersListsEntities' => ['getUsersListsEntities'],
        ];
    }

    /**
     * @return array{default: int, regular: int}
     */
    private function createDefaultAndRegularLists(): array
    {
        $created = [];
        foreach (['default' => 'Default List ', 'regular' => 'Regular List '] as $key => $prefix) {
            $name = $prefix . uniqid('', true);
            $response = $this->graphQL(
                '
                    mutation createUserList($input: UserListInput!) {
                        createUserList(input: $input) {
                            id
                        }
                    }
                ',
                [
                    'input' => [
                        'name' => $name,
                        'description' => fake()->text(),
                        'is_public' => false,
                        'is_default' => $key === 'default',
                    ],
                ]
            )->assertSuccessful();

            $created[$key] = (int) $response->json('data.createUserList.id');
        }

        return $created;
    }

    #[DataProvider('usersListsQueryProvider')]
    public function testUsersListsSortByIsDefaultPutsDefaultFirst(string $query): void
    {
        $lists = $this->createDefaultAndRegularLists();

        $this->assertOrdersByIsDefault(
            'query($ids: Mixed!, $order: SortOrder!) {
                ' . $query . '(
                    where: { column: ID, operator: IN, value: $ids }
                    orderBy: [{ column: IS_DEFAULT, order: $order }]
                ) {
                    data { id is_default }
                }
            }',
            'data.' . $query . '.data',
            $lists['default'],
            $lists['regular']
        );
    }

    #[DataProvider('usersListsQueryProvider')]
    public function testUsersListsFilterByIsDefault(string $query): void
    {
        $lists = $this->createDefaultAndRegularLists();

        $this->assertFiltersByIsDefault(
            'query($ids: Mixed!, $value: Mixed!) {
                ' . $query . '(
                    where: {
                        AND: [
                            { column: ID, operator: IN, value: $ids }
                            { column: IS_DEFAULT, operator: EQ, value: $value }
                        ]
                    }
                ) {
                    data { id is_default }
                }
            }',
            'data.' . $query . '.data',
            $lists['default'],
            $lists['regular']
        );
    }

    public function testSavingANonDefaultListKeepsTheExistingDefault(): void
    {
        $lists = $this->createDefaultAndRegularLists();

        $this->assertTrue((bool) UserList::find($lists['default'])->is_default);
        $this->assertFalse((bool) UserList::find($lists['regular'])->is_default);
    }

    public function testMarkingAListDefaultDemotesThePreviousDefault(): void
    {
        $lists = $this->createDefaultAndRegularLists();

        $regular = UserList::find($lists['regular']);
        $regular->is_default = true;
        $regular->save();

        $this->assertFalse((bool) UserList::find($lists['default'])->is_default);
        $this->assertTrue((bool) UserList::find($lists['regular'])->is_default);
    }
}
