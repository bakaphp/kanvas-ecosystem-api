<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Apps;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    public function testGetAllAppUsers()
    {
        $app = app(Apps::class);

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

        $password = fake()->password(12);

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
}
