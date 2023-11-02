<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Apps;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Enums\AppEnums;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    public function testGetAllAppUsers()
    {
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);

        $response = $this->graphQL(
            /** @lang GraphQL */
            '
            query {
                appUsers(first: 10) {
                    data {
                        id,
                        email,
                        created_at
                    },
                    paginatorInfo {
                      currentPage
                      lastPage
                    }
                }
            }
            ',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );
        $this->assertArrayHasKey('data', $response);
    }

    public function testUpdateUserPassword()
    {
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);

        $response = $this->graphQL(
            /** @lang GraphQL */
            '
            query {
                appUsers(first: 10) {
                    data {
                        uuid,
                        email,
                        created_at
                    },
                    paginatorInfo {
                      currentPage
                      lastPage
                    }
                }
            }
            ',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );

        $userList = $response->json();

        //@todo remove when we fimish migration to niche
        $user = Users::getByUuid($userList['data']['appUsers']['data'][0]['uuid']);
        $userRegisterInApp = new RegisterUsersAppAction($user);
        $userRegisterInApp->execute($user->password);

        //don't know why password gives us errors
        $password = str_replace(' ', '', fake()->realText(15));
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation{
                appUserUpdatePassword(
                    uuid: "' . $userList['data']['appUsers']['data'][0]['uuid'] . '",
                    password: "' . $password . '"
                ) 
            }',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );

        $response->assertJson([
            'data' => [
                'appUserUpdatePassword' => true,
            ],
        ]);
    }

    public function testUpdateUserEmail()
    {
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);

        $response = $this->graphQL(
            /** @lang GraphQL */
            '
            query {
                appUsers(first: 10) {
                    data {
                        uuid,
                        email,
                        created_at
                    },
                    paginatorInfo {
                      currentPage
                      lastPage
                    }
                }
            }
            ',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );

        $userList = $response->json();

        $email = fake()->email();

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation{
                appUserUpdateEmail(
                    uuid: "' . $userList['data']['appUsers']['data'][0]['uuid'] . '",
                    email: "' . $email . '"
                ) 
            }',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );

        $response->assertJson([
            'data' => [
                'appUserUpdateEmail' => true,
            ],
        ]);
    }

    public function testCreateUser()
    {
        $app = app(Apps::class);

        $user = $app->keys()->first()->user()->firstOrFail();
        $user->assign(RolesEnums::OWNER->value);

        $email = fake()->email();
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation appCreateUser($data: CreateUserInput!) {
                appCreateUser(data: $data) {
                    id
                    email
                }
              }',
            [
                'data' => [
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                    'email' => $email,
                ],
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );

        $response->assertJson([
            'data' => [
                'appCreateUser' => [
                    'email' => $email,
                ],
            ],
        ]);
    }
}
