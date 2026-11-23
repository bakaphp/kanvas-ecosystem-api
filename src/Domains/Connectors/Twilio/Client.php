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

    public static function getInstanceByCompanyOrApp(Companies $company, AppInterface $app): TwilioClient
    {
        [$sid, $token] = self::getKeysFromCompanyOrApp($company, $app);

        return new TwilioClient($sid, $token);
    }

    /**
     * The account a company's texts go through: its own when it has one, so a dealer with its own
     * account keeps sending from it, the app's otherwise. Sending, the number's webhook and the
     * signature on what Twilio posts back all have to name the same account.
     *
     * @throws ValidationException when neither has credentials
     * @return array{0: string, 1: string} [Account SID, Auth Token]
     */
    public static function getKeysFromCompanyOrApp(Companies $company, AppInterface $app): array
    {
        try {
            return self::getKeysFromCompany($company);
        } catch (ValidationException) {
            return self::getKeysFromApp($app);
        }
    }

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

    public static function ownsPhoneNumber(string $sid, string $token, string $phoneNumber): bool
    {
        $client = new TwilioClient($sid, $token);

        return $client->incomingPhoneNumbers->read(['phoneNumber' => $phoneNumber], 1) !== [];
    }

    /**
     * @throws ValidationException when the app has no credentials
     * @return array{0: string, 1: string} [Account SID, Auth Token]
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
     * @throws ValidationException when the company has no credentials
     * @return array{0: string, 1: string} [Account SID, Auth Token]
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
