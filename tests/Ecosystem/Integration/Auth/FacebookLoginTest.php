<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Auth;

use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Socialite\SocialManager;
use Kanvas\Enums\AppSettingsEnums;
use Tests\TestCase;

final class FacebookLoginTest extends TestCase
{
    public function testLoginWinJWTToken()
    {
        /**
         * @todo look for a way to make this work on the CI
         */
        $token = '';

        $app = app(Apps::class);
        $app->set(AppSettingsEnums::SOCIALITE_PROVIDER_FACEBOOK->getValue(), ['client_id' => '1234567890']);

        $socialManager = SocialManager::getDriver('facebook', $app);

        //$socialManager->getUserFromToken($token);
    }
}
