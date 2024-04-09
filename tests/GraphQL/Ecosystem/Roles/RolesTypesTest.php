<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Roles;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Tests\TestCase;

class RolesTypesTest extends TestCase
{
    public function testCreateRolesTypes()
    {
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);

        $this->graphQL(/** @lang GraphQL */
            '
            mutation($input: RoleTypeInput!) {
                createRoleType(
                    input: $input
                ) {
                    id
                    name
                    description
                }
            }',
            [
                'input' => [
                    'name' => 'No Admin',
                    'description' => 'No Admin',
                ],
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )->assertJson([
            'data' => [
                'createRoleType' => [
                    'name' => 'No Admin',
                    'description' => 'No Admin',
                ],
            ],
        ]);
    }

    public function testUpdateRolesTypes()
    {
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);

        $response = $this->graphQL(/** @lang GraphQL */
            '
            mutation($input: RoleTypeInput!) {
                createRoleType(
                    input: $input
                ) {
                    id
                    name
                    description
                }
            }',
            [
                'input' => [
                    'name' => 'No Admin',
                    'description' => 'No Admin',
                ],
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );
        $response = $response->json();
        $this->graphQL(/** @lang GraphQL */
            '
            mutation($input: RoleTypeInput!, $id: ID!) {
                updateRoleType(
                    id: $id
                    input: $input
                ) {
                    id
                    name
                    description
                }
            }',
            [
                'input' => [
                    'name' => 'Admin',
                    'description' => 'Admin',
                ],
                'id' => $response['data']['createRoleType']['id'],
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )->assertJson([
            'data' => [
                'updateRoleType' => [
                    'name' => 'Admin',
                    'description' => 'Admin',
                ],
            ],
        ]);
    }

    public function testRolesTypes()
    {

        $response = $this->graphQL(/** @lang GraphQL */
            '
            query {
                roleTypes {
                    data {
                        id
                        name
                        description
                    }
                }
            }'
        );
        $response->assertJsonStructure([
            'data' => [
                'roleTypes' => [
                    'data' => [
                        '*' => [ // Esto indica que cada elemento del array debe tener esta estructura específica.
                            'id',
                            'name',
                            'description',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
