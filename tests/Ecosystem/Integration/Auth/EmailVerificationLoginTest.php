<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Auth;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\Actions\SocialLoginAction;
use Kanvas\Auth\DataTransferObject\LoginInput;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Auth\Services\AuthenticationService;
use Kanvas\Auth\Services\EmailVerification;
use Kanvas\Auth\Socialite\DataTransferObject\User as SocialiteUser;
use Kanvas\Users\Models\Sources;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Auth\InMemorySettingsApp;
use Tests\TestCase;

final class EmailVerificationLoginTest extends TestCase
{
    private Apps $currentApp;
    private Users $user;
    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->password = fake()->password(12);

        $this->user = new RegisterUsersAction(
            RegisterInput::from([
                'email' => fake()->unique()->safeEmail(),
                'password' => $this->password,
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ])
        )->execute();
    }

    private function login(AppInterface $app): void
    {
        new AuthenticationService($app)->login(
            LoginInput::from([
                'email' => $this->user->email,
                'password' => $this->password,
                'ip' => '127.0.0.1',
            ])
        );
    }

    /**
     * The real app's settings live in Redis and MySQL, which every paratest
     * process shares — flipping the switch there would gate logins in whatever
     * else is running. This carries the app's id, so the queries still resolve,
     * with the setting held in memory.
     */
    private function appRequiringVerification(): InMemorySettingsApp
    {
        return InMemorySettingsApp::withSettings(
            ['require_email_verification' => true],
            $this->currentApp->getId()
        );
    }

    public function testAppsThatNeverOptedInAreUnaffected(): void
    {
        $this->login($this->currentApp);

        $this->assertFalse((bool) $this->user->getAppProfile($this->currentApp)->is_verified);
    }

    public function testUnverifiedUserIsBlockedWhenTheAppRequiresVerification(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Please verify your email address before logging in.');

        $this->login($this->appRequiringVerification());
    }

    public function testTheSamePasswordWorksOnceTheEmailIsVerified(): void
    {
        $app = $this->appRequiringVerification();

        new EmailVerification($app)->markVerified($this->user);

        $this->login($app);

        $profile = $this->user->getAppProfile($this->currentApp);
        $this->assertTrue((bool) $profile->is_verified);
        $this->assertNotNull($profile->email_verified_at);
    }

    public function testAWrongPasswordStillFailsAsAWrongPasswordNotAsUnverified(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid email or password.');

        new AuthenticationService($this->appRequiringVerification())->login(
            LoginInput::from([
                'email' => $this->user->email,
                'password' => $this->password . 'wrong',
                'ip' => '127.0.0.1',
            ])
        );
    }

    public function testTheBlockedMessageCanBeOverriddenPerApp(): void
    {
        $app = InMemorySettingsApp::withSettings(
            [
                'require_email_verification' => true,
                'unverified_account_error_message' => 'Check your inbox first.',
            ],
            $this->currentApp->getId()
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Check your inbox first.');

        $this->login($app);
    }

    /**
     * Google, Apple and Facebook only mint a token for a mailbox they already
     * confirmed, so signing in through one has to satisfy the gate — otherwise
     * turning the setting on locks out every social user.
     */
    public function testSocialLoginVerifiesTheProfileSoTheGatePasses(): void
    {
        $source = Sources::firstOrCreate(
            ['title' => 'google'],
            ['url' => 'https://google.com', 'language_id' => 1]
        );

        new SocialLoginAction(
            new SocialiteUser(
                id: (string) fake()->unique()->randomNumber(8),
                name: $this->user->firstname,
                email: $this->user->email,
                nickname: $this->user->displayname,
                token: 'test-token'
            ),
            $source->title,
            $this->currentApp
        )->execute();

        $this->assertTrue((bool) $this->user->getAppProfile($this->currentApp)->is_verified);

        $this->login($this->appRequiringVerification());
    }
}
