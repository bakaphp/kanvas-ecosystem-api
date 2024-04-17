<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Twilio\Rest\Client as TwilioClient;

class Client
{
    protected static ?Client $instance = null;

    /**
     * Singleton.
     */
    protected function __construct()
    {
    }

    /**
     * Connect to zoho CRM.
     */
    public static function getInstance(AppInterface $app): TwilioClient
    {
        if (self::$instance === null) {
            $sid = $app->get(ConfigurationEnum::TWILIO_ACCOUNT_SID->value);
            $token = $app->get(ConfigurationEnum::TWILIO_AUTH_TOKEN->value);
            self::$instance = new Client($sid, $token);
        }

        return self::$instance;
    }
}
