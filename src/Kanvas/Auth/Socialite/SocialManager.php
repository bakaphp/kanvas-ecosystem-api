<?php

declare(strict_types=1);

namespace Kanvas\Auth\Socialite;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Socialite\Contracts\DriverInterface;
use Kanvas\Auth\Socialite\Drivers\GoogleDriver;

class SocialManager
{
    public static function getDriver(string $driver, ?Apps $app = null): DriverInterface
    {
        $app = $app ?? app(Apps::class);

        return match ($driver) {
            'google' => new GoogleDriver($app->get('google_social_config')),
            default => throw new Exception('Driver not found'),
        };
    }
}
