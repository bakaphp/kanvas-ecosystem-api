<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Providers\Twilio;

use Baka\Contracts\AppInterface;
use DateTimeInterface;
use Exception;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
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
     * Company credentials first, the app's otherwise — the order every Twilio send has used, so a
     * dealer with its own account keeps sending from it.
     */
    public static function getInstanceByCompanyOrApp(Companies $company, AppInterface $app): TwilioClient
    {
        [$sid, $token] = self::getKeysFromCompanyOrApp($company, $app);

        return new TwilioClient($sid, $token);
    }

    /**
     * @return array{0: string, 1: string} Array containing [Account SID, Auth Token]
     */
    public static function getKeysFromCompanyOrApp(Companies $company, AppInterface $app): array
    {
        try {
            return self::getKeysFromCompany($company);
        } catch (ValidationException) {
            return self::getKeysFromApp($app);
        }
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

    public static function ownsPhoneNumber(string $sid, string $token, string $phoneNumber): bool
    {
        $client = new TwilioClient($sid, $token);

        return $client->incomingPhoneNumbers->read(['phoneNumber' => $phoneNumber], 1) !== [];
    }

    /**
     * The shape StoreMessageSidAction and RecordMessageAttemptAction read from a send result's `messages`.
     */
    public static function describeMessage(object $twilioMessage): array
    {
        return [
            'sid' => $twilioMessage->sid,
            'account_sid' => $twilioMessage->accountSid,
            'messaging_service_sid' => $twilioMessage->messagingServiceSid,
            'status' => $twilioMessage->status,
            'direction' => $twilioMessage->direction,
            'from' => $twilioMessage->from,
            'to' => $twilioMessage->to,
            'body' => $twilioMessage->body,
            'num_segments' => $twilioMessage->numSegments,
            'num_media' => $twilioMessage->numMedia,
            'error_code' => $twilioMessage->errorCode,
            'error_message' => $twilioMessage->errorMessage,
            'price' => $twilioMessage->price,
            'price_unit' => $twilioMessage->priceUnit,
            'date_created' => $twilioMessage->dateCreated?->format(DateTimeInterface::ATOM),
            'date_sent' => $twilioMessage->dateSent?->format(DateTimeInterface::ATOM),
            'date_updated' => $twilioMessage->dateUpdated?->format(DateTimeInterface::ATOM),
            'uri' => $twilioMessage->uri,
        ];
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
