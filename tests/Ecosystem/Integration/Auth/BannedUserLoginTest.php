<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Auth;

use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\LoginInput;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Auth\Services\AuthenticationService;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class BannedUserLoginTest extends TestCase
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

    private function login(): void
    {
        new AuthenticationService($this->currentApp)->login(
            LoginInput::from([
                'email' => $this->user->email,
                'password' => $this->password,
                'ip' => '127.0.0.1',
            ])
        );
    }

    public function testBannedUserCannotLoginWithTheCorrectPassword(): void
    {
        $this->login();

        $profile = $this->user->getAppProfile($this->currentApp);
        $profile->banned = 1;
        $profile->saveOrFail();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User has been banned, please contact support.');

        $this->login();
    }

    public function testIsBannedReadsTheIntegerColumn(): void
    {
        $profile = $this->user->getAppProfile($this->currentApp);

        $this->assertFalse($profile->isBanned());
        $this->assertFalse($this->user->isBanned());

        $profile->banned = 1;
        $this->user->banned = 1;

        $this->assertTrue($profile->isBanned());
        $this->assertTrue($this->user->isBanned());
    }
}
