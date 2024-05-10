<?php

declare(strict_types=1);

namespace Baka\Social;

use Baka\Social\Contracts\DriverInterface;

use Baka\Social\Drivers\GoogleDriver;
use Kanvas\Apps\Models\Apps;

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
