<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Apps;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
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
        $company = $user->getCurrentCompany();

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
                    'custom_fields' => [],
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

        $user = Users::getByEmail($email);
        $this->assertTrue($user->companies()->count() == 1);
        $this->assertTrue($user->companies()->first()->id == $company->getId());
    }

    public function testDeletedUser()
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
                    'custom_fields' => [],
                ],
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );

        $userId = $response->json('data.appCreateUser.id');

        $this->graphQL(/** @lang GraphQL */ '
            mutation appDeleteUser($user_id: ID!) {
                appDeleteUser(user_id: $user_id) 
            }',
            [
                'user_id' => $userId,
            ]
        )->assertJson([
            'data' => [
                'appDeleteUser' => true,
            ],
        ]);
    }

    public function testRestoreDeletedUser()
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
                    'custom_fields' => [],
                ],
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );

        $userId = $response->json('data.appCreateUser.id');

        $this->graphQL(/** @lang GraphQL */ '
            mutation appDeleteUser($user_id: ID!) {
                appDeleteUser(user_id: $user_id) 
            }',
            [
                'user_id' => $userId,
            ]
        )->assertJson([
            'data' => [
                'appDeleteUser' => true,
            ],
        ]);

        $this->graphQL(/** @lang GraphQL */ '
            mutation appRestoreDeletedUser($user_id: ID!) {
                appRestoreDeletedUser(user_id: $user_id) 
            }',
            [
                'user_id' => $userId,
            ]
        )->assertJson([
            'data' => [
                'appRestoreDeletedUser' => true,
            ],
        ]);
    }

    public function testCreateCompanyAssignmentUser()
    {
        $app = app(Apps::class);

        $app->del(AppSettingsEnums::ADMIN_USER_REGISTRATION_ASSIGN_CURRENT_COMPANY->getValue());

        $user = $app->keys()->first()->user()->firstOrFail();
        $user->assign(RolesEnums::OWNER->value);
        $company = $user->getCurrentCompany();

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
                    'custom_fields' => [],
                    'create_company' => true,
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

        $user = Users::getByEmail($email);
        $this->assertTrue($user->companies()->count() == 1);
        $this->assertTrue($user->companies()->first()->id != $company->getId());
    }

    public function testResetPassword()
    {
        $app = app(Apps::class);

        $user = $app->keys()->first()->user()->firstOrFail();
        $user->assign(RolesEnums::OWNER->value);

        $email = fake()->email();
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation appCreateUser($data: CreateUserInput!) {
                appCreateUser(data: $data) {
                    id
                    email,
                    uuid
                }
              }',
            [
                'data' => [
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                    'email' => $email,
                    'custom_fields' => [],
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

        $user = Users::getByEmail($email);
        $this->graphQL(/** @lang GraphQL */ '
            mutation appResetUserPassword($user_id: ID!, $password: String!) {
                appResetUserPassword(user_id: $user_id, password: $password) 
            }',
            [
                'user_id' => $user->uuid,
                'password' => 'password',
            ]
        )->assertJson([
            'data' => [
                'appResetUserPassword' => true,
            ],
        ]);
    }

    public function testUpdateAdminDisplayname()
    {
        $app = app(Apps::class);

        $user = $app->keys()->first()->user()->firstOrFail();
        $user->assign(RolesEnums::OWNER->value);

        $email = fake()->email();
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation appCreateUser($data: CreateUserInput!) {
                appCreateUser(data: $data) {
                    id
                    email,
                    uuid
                }
              }',
            [
                'data' => [
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                    'email' => $email,
                    'custom_fields' => [],
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

        $user = Users::getByEmail($email);
        $this->graphQL(/** @lang GraphQL */ '
            mutation appUpdateUserDisplayname($user_id: ID!, $displayname: String!) {
                appUpdateUserDisplayname(user_id: $user_id, displayname: $displayname) 
            }',
            [
                'user_id' => $user->getId(),
                'displayname' => fake()->userName(),
            ]
        )->assertJson([
            'data' => [
                'appUpdateUserDisplayname' => true,
            ],
        ]);
    }

    public function testGetAllAppAdminUsers()
    {
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);

        $response = $this->graphQL(
            /** @lang GraphQL */
            '
            query {
                appAdmins(first: 10) {
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
}
