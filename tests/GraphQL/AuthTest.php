<?php
declare(strict_types=1);

namespace Tests\GraphQL;

use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthTest extends TestCase
{
    protected static array $loginData = [];

    /**
     * Set login credentials.
     *
     * @return array
     */
    public static function loginData() : array
    {
        if (empty(self::$loginData)) {
            self::$loginData = [
                'email' => fake()->email,
                'password' => fake()->password . fake()->password
            ];
        }

        return self::$loginData;
    }

    /**
     * test_save.
     *
     * @return void
     */
    public function test_signup() : void
    {
        $loginData = self::loginData();
        $email = $loginData['email'];
        $password = $loginData['password'];

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation register($data: RegisterInput!) {
                register(data: $data) {
                  user{
                    email
                  }
                  token{
                      token
                      refresh_token
                      token_expires
                      refresh_token_expires
                      time
                      timezone
                  }
                }
              }
        ', [
            'data' => [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password
            ],
        ])->assertJson([
            'data' => [
                'register' => [
                    'user' => [
                        'email' => $email,
                    ]
                ],
            ],
        ])
        ->assertSee('token')
        ->assertSee('token_expires')
        ->assertSee('refresh_token_expires')
        ->assertSee('time')
        ->assertSee('timezone')
        ->assertSee('refresh_token');
    }

    /**
     * test_save.
     *
     * @return void
     */
    public function test_login() : void
    {
        $loginData = self::loginData();
        $email = $loginData['email'];
        $password = $loginData['password'];

        $response = $this->graphQL(/** @lang GraphQL */ '
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
                'password' => $password
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

    public function test_auth_user() : void
    {
        $userData = Auth::user();
        $response = $this->graphQL(/** @lang GraphQL */ '
            {
                me {
                    id
                    displayname
                    email
                }
            }
        ')
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'me' => [
                    'id' => $userData->id,
                    'displayname' => $userData->displayname,
                    'email' => $userData->email
                ]
            ],
        ]);
    }
}
