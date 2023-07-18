<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use Kanvas\Auth\DataTransferObject\LoginInput;
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
        $email = $userDataProfile['data']['me']['email'];

        $this->graphQL(/** @lang GraphQL */ '
            mutation changePassword(
                $new_password: String!
                $new_password_confirmation: String
            ) {
                changePassword(
                    new_password: $new_password
                    new_password_confirmation: $new_password_confirmation)
            }
        ', [
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
}
