<?php

declare(strict_types=1);

namespace Kanvas\Auth\Social;

use Kanvas\Apps\Models\Apps;

use Kanvas\Auth\Social\Contracts\DriverInterface;
use Kanvas\Auth\Social\Drivers\GoogleDriver;

class SocialManager
{
    public static function getDriver(string $driver, ?Apps $app = null): DriverInterface
    {
        $app = $app ?? app(Apps::class);
        switch ($driver) {
            case 'google':
                $config = $app->get('google_social_config');

                return new GoogleDriver($config);
            default:
                throw new Exception('Driver not found');
        }
    }
}
