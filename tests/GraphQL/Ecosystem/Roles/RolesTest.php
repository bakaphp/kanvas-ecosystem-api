<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Roles;

use Kanvas\Apps\Enums\DefaultRoles;
use Tests\TestCase;

class RolesTest extends TestCase
{
    public function testCreateRole(): void
    {
        $response = $this->graphQL(
            "
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
            }"
        );
        $modules = $response->json("data.kanvasModules");
        $modelName = $modules[0];
        $systemModules = $modelName["systemModules"][0]["model_name"];
        $permissions = collect($modules[0]["systemModules"][0]["abilities"]);
        $permissions = $permissions->pluck("name")->toArray();
        $permissions = [
            "model_name" => $modelName,
            "permission" => $permissions
        ];
        $input = [
            "name" => fake()->name,
            "title" => fake()->name,
            "permissions" => [$permissions]
        ];
        $this->graphQL('
            mutation createRole($input: RoleInput!) {
                createRole(input: $input) {
                    name
                    title
                }
            }
        ', [
            'input' => $input
        ])->assertJson([
            'data' => [
                'createRole' => [
                    'name' => $input['name'],
                    'title' => $input['title']
                ]
            ]
        ]);
    }

    public function testUpdateRole(): void
    {
        $response = $this->graphQL(
            "
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
            }"
        );
        $modules = $response->json("data.kanvasModules");
        $systemModules = $modules[0]["systemModules"];
        $modelName = $systemModules[0]["model_name"];
        $permissions = collect($modules[0]["systemModules"][0]["abilities"]);
        $permissions = $permissions->pluck("name")->toArray();
        $permissions = [
            "model_name" => $modelName,
            "permission" => $permissions
        ];
        $input = [
            "name" => fake()->name,
            "title" => fake()->name,
            "permissions" => [$permissions]
        ];
        $roleId = $this->graphQL('
            mutation createRole($input: RoleInput!) {
                createRole(input: $input) {
                    id
                    name
                    title
                }
            }
        ', [
            'input' => $input
        ])->json('data.createRole.id');

        $input['name'] = fake()->name;
        $this->graphQL('
            mutation updateRole($id: ID!, $input: RoleInput!) {
                updateRole(id: $id, input: $input) {
                    name
                    title
                }
            }
        ', [
            'id' => $roleId,
            'input' => $input
        ])->assertJson([
                    'data' => [
                        'updateRole' => [
                            'name' => $input['name'],
                            'title' => $input['title']
                        ]
                    ]
                ]);
    }
}
