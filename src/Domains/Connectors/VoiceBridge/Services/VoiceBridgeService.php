<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Services;

use Kanvas\Connectors\VoiceBridge\DataTransferObject\VoiceBridge as VoiceBridgeDto;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum;

class VoiceBridgeService
{
    /**
     * Persist VoiceBridge credentials into app configuration.
     * All companies within the app share the same integration.
     */
    public static function setup(VoiceBridgeDto $dto): bool
    {
        $dto->app->set(ConfigurationEnum::API_KEY->value, $dto->apiKey);
        $dto->app->set(ConfigurationEnum::BASE_URL->value, $dto->baseUrl);
        $dto->app->set(ConfigurationEnum::COMPANY_ID->value, $dto->companyId);

        return true;
    }

    /**
     * Remove VoiceBridge credentials from app configuration.
     */
    public static function teardown(VoiceBridgeDto $dto): bool
    {
        $dto->app->del(ConfigurationEnum::API_KEY->value);
        $dto->app->del(ConfigurationEnum::BASE_URL->value);
        $dto->app->del(ConfigurationEnum::COMPANY_ID->value);

        return true;
    }

    /**
     * Build an outbound session ID in the required format: voice-{leadId}-{digitsOnly}-{companyId}.
     * The phone number is stripped to digits only (no +, no -).
     */
    public static function buildOutboundSessionId(string $leadId, string $phone, string $companyId): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return sprintf('voice-%s-%s-%s', $leadId, $digits, $companyId);
    }
}
