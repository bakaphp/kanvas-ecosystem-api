<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use Illuminate\Support\Facades\Mail;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\DataTransferObject\LoginInput;
use Kanvas\Enums\AppEnums;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class UserTest extends TestCase
{
    protected static LoginInput $loginData;

    /**
     * Set login credentials.
     */
    public static function loginData(): LoginInput
    {
        if (empty(self::$loginData)) {
            self::$loginData = LoginInput::from([
                'email' => fake()->email,
                'password' => fake()->password(8),
                'ip' => request()->ip(),
            ]);
        }

        return self::$loginData;
    }

    public function testEditUserdata(): void
    {
        $loginData = self::loginData();
        $firstname = fake()->firstName();
        $lastname = fake()->lastName();
        $displayname = fake()->firstName();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data)
                {
                    firstname
                    lastname
                    displayname
                    description
                    sex,
                    custom_fields{
                        data{
                          name,
                          value
                        }
                    }
                }
            }',
            [
                'id' => 0,
                'data' => [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'displayname' => $displayname,
                    'description' => fake()->text(30),
                    'sex' => 'U',
                    'phone_number' => fake()->phoneNumber(),
                    'address_1' => fake()->address(),
                    'custom_fields' => [
                        [
                            'name' => 'test',
                            'data' => 'test',
                        ],
                    ],
                    'files' => [
                        [
                            'name' => 'photo',
                            'url' => fake()->url,
                        ],
                    ],
                ],
            ]
        )
        ->assertSuccessful()
        ->assertSee('firstname', $firstname)
        ->assertSee('lastname', $lastname)
        ->assertSee('displayname', $displayname)
        ->assertSee('description')
        ->assertSee('custom_fields')
        ->assertSee('sex');
    }

    public function testChangePassword()
    {
        $newPassword = 'abc123456';
        $currentPassword = 'abcabc123456';
        $userData = $this->graphQL(/** @lang GraphQL */ '
            { 
                me {
                    id,
                    uuid,
                    email
                }
            }
        ');

        $userDataProfile = $userData->json();
        $user = Users::getById($userDataProfile['data']['me']['id']);
        $user->resetPassword($currentPassword, app(Apps::class));

        $email = $userDataProfile['data']['me']['email'];

        $this->graphQL(/** @lang GraphQL */ '
            mutation changePassword(
                $current_password: String!
                $new_password: String!
                $new_password_confirmation: String
            ) {
                changePassword(
                    current_password: $current_password
                    new_password: $new_password
                    new_password_confirmation: $new_password_confirmation)
            }
        ', [
            'current_password' => $currentPassword,
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ])->assertJson([
            'data' => [
                'changePassword' => true,
            ],
        ]);

        $this->graphQL(/** @lang GraphQL */ '
            mutation login($data: LoginInput!) {
                login(data: $data) {
                  id
                  token
                  refresh_token
                  token_expires
                  refresh_token_expires
                  time
                  timezone
                }
              }

        ', [
            'data' => [
                'email' => $email,
                'password' => $currentPassword,
            ],
        ])
        ->assertSuccessful()
        ->assertSee('errors')
        ->assertSee('message')
        ->assertSee('Invalid email or password.');

        $this->graphQL(/** @lang GraphQL */ '
            mutation login($data: LoginInput!) {
                login(data: $data) {
                  id
                  token
                  refresh_token
                  token_expires
                  refresh_token_expires
                  time
                  timezone
                }
              }

        ', [
            'data' => [
                'email' => $email,
                'password' => $newPassword,
            ],
        ])
        ->assertSuccessful()
        ->assertSee('id')
        ->assertSee('token')
        ->assertSee('token_expires')
        ->assertSee('refresh_token_expires')
        ->assertSee('time')
        ->assertSee('timezone')
        ->assertSee('refresh_token');
    }

    public function testChangeEmail(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation updateEmail(
                $email: String!
            ) {
                updateEmail(
                    email: $email
                )
            }
        ', [
            'email' => fake()->email,
        ])->assertJson([
            'data' => [
                'updateEmail' => true,
            ],
        ]);
    }

    public function testChangeDisplayName(): void
    {
        Mail::fake();

        $this->graphQL(/** @lang GraphQL */ '
            mutation updateDisplayname(
                $displayname: String!
            ) {
                updateDisplayname(
                    displayname: $displayname
                )
            }
        ', [
            'displayname' => fake()->userName(),
        ])->assertJson([
            'data' => [
                'updateDisplayname' => true,
            ],
        ]);
    }

    public function testSetUserSetting()
    {
        $app = app(Apps::class);

        $input = [
            'key' => 'test',
            'value' => 'test',
            'entity_uuid' => Users::getById(1)->uuid,
        ];
        $this->graphQL(/** @lang GraphQL */
            '
            mutation setUserSetting($input: ModuleConfigInput!){
                setUserSetting(input: $input)
            }
        ',
            [
            'input' => $input,
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )->assertJson([
            'data' => [
                'setUserSetting' => true,
            ],
        ]);
    }

    public function testDeleteUserSetting()
    {
        $app = app(Apps::class);

        $input = [
            'key' => 'test',
            'value' => 'test',
            'entity_uuid' => Users::getById(1)->uuid,
        ];
        $this->graphQL(/** @lang GraphQL */
            '
            mutation deleteUserSetting($input: ModuleConfigInput!){
                deleteUserSetting(input: $input)
            }
        ',
            [
            'input' => $input,
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )->assertJson([
            'data' => [
                'deleteUserSetting' => true,
            ],
        ]);
    }

    public function testRequestDeleteAccount()
    {
        $this->graphQL(/** @lang GraphQL */
            '
            mutation requestDeleteAccount {
                requestDeleteAccount
            }
        '
        )->assertJson([
            'data' => [
                'requestDeleteAccount' => true,
            ],
        ]);


    }
}
