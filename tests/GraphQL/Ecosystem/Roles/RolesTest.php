<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Roles;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Tests\TestCase;

class RolesTest extends TestCase
{
    public function testCreateRole(): void
    {
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);

        $response = $this->graphQL(
            '
            query {
                kanvasModules {
                    id,
                    name,
                    systemModules {
                        id,
                        name,
                        model_name,
                        abilities {
                            name 
                        }
                    }
                }
            }',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );
        $modules = $response->json('data.kanvasModules');
        $systemModules = $modules[0]['systemModules'];
        $modelName = $systemModules[0]['model_name'];
        $permissions = collect($modules[0]['systemModules'][0]['abilities']);
        $permissions = $permissions->pluck('name')->toArray();
        $permissions = [
            'model_name' => $modelName,
            'permission' => $permissions,
        ];
        $input = [
            'name' => fake()->name,
            'title' => fake()->name,
            'permissions' => [$permissions],
        ];
        $this->graphQL(
            query: '
            mutation createRole($input: RoleInput!) {
                createRole(input: $input) {
                    id
                    name
                    title
                }
            }
        ',
            variables: [
            'input' => $input,
        ],
            headers: [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]
        )->assertJson([
            'data' => [
                'createRole' => [
                    'name' => $input['name'],
                    'title' => $input['title'],
                ],
            ],
        ]);
    }

    public function testUpdateRole(): void
    {
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);
        $response = $this->graphQL(
            query: '
            query {
                kanvasModules {
                    id,
                    name,
                    systemModules {
                        id,
                        name,
                        model_name,
                        abilities {
                            name 
                        }
                    }
                }
            }',
            headers: [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );
        $modules = $response->json('data.kanvasModules');
        $systemModules = $modules[0]['systemModules'];
        $modelName = $systemModules[0]['model_name'];
        $permissions = collect($modules[0]['systemModules'][0]['abilities']);
        $permissions = $permissions->pluck('name')->toArray();
        $permissions = [
            'model_name' => $modelName,
            'permission' => $permissions,
        ];
        $input = [
            'name' => fake()->name,
            'title' => fake()->name,
            'permissions' => [$permissions],
        ];
        $response = $this->graphQL('
            mutation createRole($input: RoleInput!) {
                createRole(input: $input) {
                    id
                    name
                    title
                }
            }
        ', [
            'input' => $input,
        ]);
        $roleId = $response->json('data.createRole.id');
        $input['name'] = fake()->name;
        $response = $this->graphQL(query: '
            mutation updateRole($id: ID!, $input: RoleInput!) {
                updateRole(id: $id, input: $input) {
                    name
                    title
                }
            }
        ', variables: [
            'id' => $roleId,
            'input' => $input,
        ], headers: [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]);
        $response->assertJson([
                    'data' => [
                        'updateRole' => [
                            'name' => $input['name'],
                            'title' => $input['title'],
                        ],
                    ],
                ]);
    }

    public function testAddRoleUser(): void
    {
        $roles = $this->getRoles()['data'];
        $user = auth()->user();

        $this->graphQL(
            query: '
            mutation assignRoleToUser($userId: ID!, $roleIds: [ID!]!) {
                assignRoleToUser(userId: $userId, roleIds: $roleIds)
            }
        ',
            variables: [
            'userId' => $user->getId(),
            'roleIds' => [$roles[0]['id']],
        ]
        )->assertJson([
            'data' => [
                'assignRoleToUser' => true,
            ],
        ]);
    }

    private function getRoles()
    {
        $response = $this->graphQL('
            query {
                roles {
                  data {
                    id
                    name
                  }
                }
            }
        ');

        $this->assertArrayHasKey('id', $response->json()['data']['roles']['data'][1]);

        return $response->json()['data']['roles'];
    }
}
