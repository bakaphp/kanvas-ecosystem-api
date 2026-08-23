<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\DataTransferObject\LoginInput;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Auth\Services\AuthenticationService;
use Kanvas\Auth\Services\ForgotPassword as ForgotPasswordService;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
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
                'password' => fake()->password(9),
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
     */
    public function testSocialLogin(): void
    {
        $this->markTestSkipped('Requires social login token generation — see @todo above');
    }

    /**
     * test signup.
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
        ->assertJsonStructure([
            'data' => [
                'me' => [
                    'id',
                    'displayname',
                    'email',
                ],
            ],
        ]);

        $this->assertNotNull($response->json('data.me.id'));
        $this->assertNotNull($response->json('data.me.email'));
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
        $app = app(Apps::class);

        $authentically = $userData->getAppProfile($app);

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

    /**
     * An association row whose users_id points at a missing users record must not
     * fatal with "Call to a member function generateForgotHash() on null" — it
     * should surface a clean not-found instead (Sentry KANVAS-ECOSYSTEM-660).
     */
    public function testForgotPasswordWithOrphanedAssociationThrowsNotFound(): void
    {
        $app = app(Apps::class);
        $email = fake()->unique()->safeEmail();
        $orphanUserId = 999999999;

        // This test doesn't run inside a transaction, so scrub any row a prior run
        // leaked — the unique key is (users_id, apps_id, companies_id).
        UsersAssociatedApps::where('users_id', $orphanUserId)
            ->where('apps_id', $app->getId())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->delete();

        UsersAssociatedApps::create([
            'users_id' => $orphanUserId,
            'apps_id' => $app->getId(),
            'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'email' => $email,
            'displayname' => $email,
            'password' => '',
        ]);

        try {
            $this->expectException(ExceptionsModelNotFoundException::class);

            new ForgotPasswordService($app)->forgot($email);
        } finally {
            UsersAssociatedApps::where('users_id', $orphanUserId)
                ->where('apps_id', $app->getId())
                ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
                ->delete();
        }
    }

    /**
     * A banned account must not authenticate even with the right password. The banned
     * check used to sit in an `elseif` after the success branch, so a correct password
     * on an active account returned before it was ever evaluated.
     */
    public function testBannedUserCannotLoginWithCorrectPassword(): void
    {
        $app = app(Apps::class);
        $email = fake()->unique()->safeEmail();
        $password = fake()->password(12);

        $this->registerUser($email, $password);

        $profile = Users::getByEmail($email)->getAppProfile($app);
        $profile->banned = 1;
        $profile->saveOrFail();

        $this->expectException(AuthenticationException::class);

        new AuthenticationService($app)->login(
            LoginInput::from([
                'email' => $email,
                'password' => $password,
                'ip' => request()->ip(),
            ])
        );
    }

    /**
     * resetPassword() blanks user_activation_forgot after use, so an empty hash would
     * otherwise match every already-reset account in the app.
     */
    public function testResetPasswordRejectsBlankHashKey(): void
    {
        $app = app(Apps::class);

        $this->expectException(ExceptionsModelNotFoundException::class);

        new ForgotPasswordService($app)->reset('11223344', '   ');
    }

    private function registerUser(string $email, string $password): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation register($data: RegisterInput!) {
                register(data: $data) {
                    user { email }
                }
            }
        ', [
            'data' => [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
            ],
        ])->assertSuccessful();
    }

    public function testForgotPasswordDisplayname(): void
    {
        $loginData = self::loginData();
        $email = $loginData->getEmail();
        $userData = Users::getByEmail($loginData->getEmail());

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation forgotPassword($data: ForgotPasswordInput!) {
                forgotPassword(data: $data)
            }',
            [
                'data' => [
                    'email' => $userData->displayname,
                ],
            ]
        )
        ->assertSuccessful()
        ->assertSee('forgotPassword');
    }
}
