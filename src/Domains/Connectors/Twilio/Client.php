<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use KanvasExceptions\ValidationException;
use Twilio\Rest\Client as TwilioClient;

/**
 * Manages Twilio SDK instances for different app-company combinations.
 */
final class Client
{
    private const MAX_INSTANCES = 100;
    private static array $instances = [];

    private function __construct()
    {
    }

    /**
     * Get or create a Twilio client instance for the given app.
     */
    public static function getInstance(AppInterface $app): TwilioClient
    {
        $key = self::getConnectionKey($app);

        if (! isset(self::$instances[$key])) {
            self::$instances[$key] = self::createInstanceFromApp($app);
            self::cleanupOldInstances();
        }

        return self::$instances[$key];
    }

    /**
     * Get or create a Twilio client instance for the given company.
     */
    public static function getInstanceByCompany(Companies $company): TwilioClient
    {
        $key = self::getConnectionKeyByCompany($company);

        if (! isset(self::$instances[$key])) {
            self::$instances[$key] = self::createInstanceFromCompany($company);
            self::cleanupOldInstances();
        }

        return self::$instances[$key];
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

        return [(string) $sid, (string) $token];
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

    private static function getConnectionKey(AppInterface $app): string
    {
        return sprintf('app_%s', $app->getId());
    }

    private static function getConnectionKeyByCompany(Companies $company): string
    {
        return sprintf('company_%d', $company->id);
    }

    private static function createInstanceFromApp(AppInterface $app): TwilioClient
    {
        [$sid, $token] = self::getKeysFromApp($app);

        return new TwilioClient($sid, $token);
    }

    private static function createInstanceFromCompany(Companies $company): TwilioClient
    {
        [$sid, $token] = self::getKeysFromCompany($company);

        return new TwilioClient($sid, $token);
    }

    private static function cleanupOldInstances(): void
    {
        if (count(self::$instances) > self::MAX_INSTANCES) {
            array_shift(self::$instances);
        }
    }
}
