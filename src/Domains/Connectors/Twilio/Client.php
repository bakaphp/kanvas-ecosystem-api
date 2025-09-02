<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Twilio\Rest\Client as TwilioClient;
use Kanvas\Companies\Models\Companies;
class Client
{
    protected static ?TwilioClient $instance = null;

    /**
     * Singleton.
     */
    protected function __construct()
    {
    }

    /**
     * Connect to zoho CRM.
     */
    public static function getInstance(AppInterface|Companies $entity): TwilioClient
    {
        if (self::$instance === null) {
            $sid = $entity->get(ConfigurationEnum::TWILIO_ACCOUNT_SID->value);
            $token = $entity->get(ConfigurationEnum::TWILIO_AUTH_TOKEN->value);
            dump($sid, $token,$entity);
            self::$instance = new TwilioClient($sid, $token);
        }

        return self::$instance;
    }

    public static function validateCredentials(string $sid, string $token): bool
    {
        try {
            $client = new TwilioClient($sid, $token);
            $client->api->v2010->accounts->read([], 1);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
