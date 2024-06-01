<?php

declare(strict_types=1);

namespace Kanvas\Auth\Socialite;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Socialite\Contracts\DriverInterface;
use Kanvas\Auth\Socialite\Drivers\FacebookDriver;
use Kanvas\Auth\Socialite\Drivers\GoogleDriver;
use Kanvas\Enums\AppSettingsEnums;

class SocialManager
{
    public static function getDriver(string $driver, ?Apps $app = null): DriverInterface
    {
        $app = $app ?? app(Apps::class);

        return match ($driver) {
            'google' => new GoogleDriver($app->get(AppSettingsEnums::SOCIALITE_PROVIDER_GOOGLE->getValue())),
            'facebook' => new FacebookDriver($app->get(AppSettingsEnums::SOCIALITE_PROVIDER_FACEBOOK->getValue())),
            default => throw new Exception('Driver not found'),
        };
    }
}
