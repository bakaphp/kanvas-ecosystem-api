<?php

declare(strict_types=1);

namespace Kanvas\Auth\Socialite;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Socialite\Contracts\DriverInterface;
use Kanvas\Auth\Socialite\Drivers\AppleDriver;
use Kanvas\Auth\Socialite\Drivers\FacebookDriver;
use Kanvas\Auth\Socialite\Drivers\GoogleDriver;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Enums\SourceEnum;

class SocialManager
{
    public static function getDriver(string $driver, ?Apps $app = null): DriverInterface
    {
        $app = $app ?? app(Apps::class);

        return match ($driver) {
            SourceEnum::GOOGLE->value => new GoogleDriver($app->get(AppSettingsEnums::SOCIALITE_PROVIDER_GOOGLE->getValue())),
            SourceEnum::FACEBOOK->value => new FacebookDriver($app->get(AppSettingsEnums::SOCIALITE_PROVIDER_FACEBOOK->getValue())),
            SourceEnum::APPLE->value => new AppleDriver($app->get(AppSettingsEnums::SOCIALITE_PROVIDER_APPLE->getValue())),
            default => throw new Exception('Driver not found'),
        };
    }
}
