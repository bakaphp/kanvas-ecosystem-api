<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Twilio\Rest\Client as TwilioClient;

final class Client
{
    private function __construct()
    {
    }

    public static function getInstance(AppInterface $app): TwilioClient
    {
        [$sid, $token] = self::getKeysFromApp($app);

        return new TwilioClient($sid, $token);
    }

    public static function getInstanceByCompany(Companies $company): TwilioClient
    {
        [$sid, $token] = self::getKeysFromCompany($company);

        return new TwilioClient($sid, $token);
    }

    /**
     * Validate Twilio credentials.
     */
    public static function validateCredentials(string $sid, string $token): bool
    {
        try {
            $client = new TwilioClient($sid, $token);
            $client->api->v2010->accounts->read([], 1);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get Twilio API credentials from app configuration.
     *
     * @throws ValidationException If credentials are not properly set
     * @return array{0: string, 1: string} Array containing [Account SID, Auth Token]
     */
    public static function getKeysFromApp(AppInterface $app): array
    {
        $sid = $app->get(ConfigurationEnum::TWILIO_ACCOUNT_SID->value);
        $token = $app->get(ConfigurationEnum::TWILIO_AUTH_TOKEN->value);

        if (empty($sid) || empty($token)) {
            throw new ValidationException(
                sprintf(
                    'Twilio credentials are not set for app (ID: %s)',
                    $app->getId()
                )
            );
        }

        return [
            (string) $sid,
            (string) $token,
        ];
    }

    /**
     * Get Twilio API credentials from company configuration.
     *
     * @throws ValidationException If credentials are not properly set
     * @return array{0: string, 1: string} Array containing [Account SID, Auth Token]
     */
    public static function getKeysFromCompany(Companies $company): array
    {
        $sid = $company->get(ConfigurationEnum::TWILIO_ACCOUNT_SID->value);
        $token = $company->get(ConfigurationEnum::TWILIO_AUTH_TOKEN->value);

        if (empty($sid) || empty($token)) {
            throw new ValidationException(
                sprintf(
                    'Twilio credentials are not set for company %s (ID: %d)',
                    $company->name,
                    $company->id
                )
            );
        }

        return [(string) $sid, (string) $token];
    }
}
