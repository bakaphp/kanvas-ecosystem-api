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

    /**
     * Test the logout function to remove sessions
     */
    public function testLogout(): void
    {
        $loginData = self::loginData();
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation {
                logout
            }'
        )
        ->assertSuccessful()
        ->assertSee('logout');
    }

    /**
     * Test the logout function to remove sessions
     */
    public function testLogoutFromAllDevices(): void
    {
        $loginData = self::loginData();
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation {
                logoutFromAllDevices
            }'
        )
        ->assertSuccessful()
        ->assertSee('logout');
    }

    /**
     * Test if the user is allow to login using social media
     * @todo Look for a way to generate and pass the user token for the login using
     * a test account.
     *
     * @return void
     */
    public function testSocialLogin(): void
    {
    }

    /**
     * test_save.
     */
    public function testSignup(): void
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
                'password_confirmation' => $password,
            ],
        ])->assertJson([
            'data' => [
                'register' => [
                    'user' => [
                        'email' => $email,
                    ],
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
     */
    public function testLogin(): void
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
                'password' => $password,
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

    /**
     * test_refresh_token
     */
    public function testRefreshToken(): void
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
                'password' => $password,
            ],
        ]);
        $refreshToken = $response['data']['login']['refresh_token'];
        $this->graphQL(/** @lang GraphQL */ '
            mutation refreshToken($refresh_token: String!) {
                refreshToken(refresh_token: $refresh_token) {
                  id
                  token
                  refresh_token
                  token_expires
                  refresh_token_expires
                  time
                  timezone
                }
              }', [
            'refresh_token' => $refreshToken,
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

    public function testAuthUser(): void
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
                    'email' => $userData->email,
                ],
            ],
        ]);
    }

    /**
     * Test the forgot password hash creation and email.
     */
    public function testForgotPassword(): void
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
                    'email' => $email,
                ],
            ]
        )
        ->assertSuccessful()
        ->assertSee('forgotPassword');
    }

    /**
     * Test the reset password for user.
     */
    public function testResetPassword(): void
    {
        $emailData = self::loginData();
        $userData = Users::getByEmail($emailData->getEmail());
        $authentically = $userData->getAppProfile();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation resetPassword($data: ResetPasswordInput!) {
                resetPassword(data: $data)
            }',
            [
                'data' => [
                    'new_password' => '11223344',
                    'verify_password' => '11223344',
                    'hash_key' => $authentically->user_activation_forgot,
                ],
            ]
        )
        ->assertSuccessful()
        ->assertSee('resetPassword');
    }
}
