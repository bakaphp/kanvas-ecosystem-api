<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\Auth\DataTransferObject\LoginInput;
use Tests\TestCase;

class UserTest extends TestCase
{
    protected static LoginInput $loginData;

    /**
     * Set login credentials.
     *
     * @return LoginInput
     */
    public static function loginData(): LoginInput
    {
        if (empty(self::$loginData)) {
            self::$loginData = LoginInput::from([
                'email' => fake()->email,
                'password' => fake()->password(8),
                'ip' => request()->ip()
            ]);
        }

        return self::$loginData;
    }

    public function testEditUserData(): void
    {
        $loginData = self::loginData();
        $firstname = fake()->firstName();
        $lastname = fake()->lastName();
        $displayname = fake()->firstName();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation updateUser($data: UpdateUserInput!) {
                updateUser(data: $data)
                {
                    firstname
                    lastname
                    displayname
                }
            }',
            [
                'data' => [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'displayname' => $displayname
                ],
            ]
        )
        ->assertSuccessful()
        ->assertSee('firstname', $firstname)
        ->assertSee('lastname', $lastname)
        ->assertSee('displayname', $displayname);
    }

    public function testChangePassword()
    {
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
            'new_password' => 'abc123456',
            'new_password_confirmation' => 'abc123456'
        ])->assertJson([
            'data' => [
                'changePassword' => true
            ]
        ]);
    }
}
