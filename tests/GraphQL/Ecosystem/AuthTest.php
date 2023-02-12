<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Illuminate\Support\Facades\Auth;
use Kanvas\Auth\DataTransferObject\LoginInput;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class AuthTest extends TestCase
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

    /**
     * test_save.
     *
     * @return void
     */
    public function test_signup(): void
    {
        $loginData = self::loginData();
        $email = $loginData->getEmail();
        $password = $loginData->getPassword();

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
    public function test_login(): void
    {
        $loginData = self::loginData();
        $email = $loginData->getEmail();
        $password = $loginData->getPassword();

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

    public function test_auth_user(): void
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

    /**
     * Test the forgot password hash creation and email.
     *
     * @return void
     */
    public function test_forgot_password(): void
    {
        $loginData = self::loginData();
        $email = $loginData->getEmail();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation forgotPassword($data: ForgotPasswordInput!) {
                forgotPassword(data: $data)
            }',
            [
                'data' => [
                    'email' => $email
                ],
            ]
        )
        ->assertSuccessful()
        ->assertSee('forgotPassword');
    }

    /**
     * Test the reset password for user.
     *
     * @return void
     */
    public function test_reset_password(): void
    {
        $emailData = self::loginData();
        $userData = Users::getByEmail($emailData->getEmail());

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation resetPassword($data: ResetPasswordInput!) {
                resetPassword(data: $data)
            }',
            [
                'data' => [
                    'new_password' => '11223344',
                    'verify_password' => '11223344',
                    'hash_key' => $userData->user_activation_forgot
                ],
            ]
        )
        ->assertSuccessful()
        ->assertSee('resetPassword');
    }
}
