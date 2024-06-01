<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Auth;

use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\SocialLoginAction;
use Kanvas\Auth\Socialite\DataTransferObject\User;
use Kanvas\Auth\Socialite\SocialManager;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Enums\SourceEnum;
use Tests\TestCase;

final class AppleLoginTest extends TestCase
{
    public function testLoginWinToken()
    {
        $token = env('TEST_APPLE_LOGIN_TOKEN');

        $app = app(Apps::class);
        $app->set(AppSettingsEnums::SOCIALITE_PROVIDER_APPLE->getValue(), []);

        $socialManager = SocialManager::getDriver(SourceEnum::APPLE->value, $app);

        $user = $socialManager->getUserFromToken($token);
        
        $socialLogin = new SocialLoginAction($user, SourceEnum::APPLE->value, $app);
        $loggedUser = $socialLogin->execute();
        $tokenResponse = $loggedUser->createToken(name: 'test-loging-apple')->toArray();

        $this->assertIsObject($user);
        $this->assertInstanceOf(User::class, $user);
        $this->assertNotEmpty($user->id);
        $this->assertNotEmpty($user->name);
        $this->assertNotEmpty($user->email);
        $this->assertIsArray($tokenResponse);
        $this->assertArrayHasKey('token', $tokenResponse);
        $this->assertArrayHasKey('refresh_token', $tokenResponse);
        $this->assertArrayHasKey('id', $tokenResponse);
    }
}
