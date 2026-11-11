<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Handlers;

use Baka\Support\Str;
use Exception;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use Override;

class TwilioHandlers extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $accountSid = $this->data['account_sid'] ?? '';
        $authToken = $this->data['auth_token'] ?? '';
        $validated = $this->validateCredentials($accountSid, $authToken);
        if (! $validated) {
            throw new Exception('Failed to validate Twilio connection');
        }

        $fromNumber = Str::trimToNull($this->data['from_number'] ?? null);
        if ($fromNumber !== null) {
            $fromNumber = AgentsRepository::cleanPhoneNumber($fromNumber);
            if (! $this->ownsPhoneNumber($accountSid, $authToken, $fromNumber)) {
                throw new Exception("The number {$fromNumber} is not on this Twilio account");
            }
        }

        $previousSid = $this->company->get(ConfigurationEnum::TWILIO_ACCOUNT_SID->value);
        $this->company->set(ConfigurationEnum::TWILIO_ACCOUNT_SID->value, $accountSid);
        $this->company->set(ConfigurationEnum::TWILIO_AUTH_TOKEN->value, $authToken);

        // The from number is the outbound caller id for SMS and the voice agents' fallback; one
        // left over from another account makes every call and text fail at Twilio.
        if ($fromNumber !== null) {
            $this->company->set(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value, $fromNumber);
        } elseif ($previousSid !== null && $previousSid !== $accountSid) {
            $this->company->del(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value);
        }

        return true;
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return [
            ConfigurationEnum::TWILIO_ACCOUNT_SID->value,
            ConfigurationEnum::TWILIO_AUTH_TOKEN->value,
            ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value,
        ];
    }

    protected function validateCredentials(string $accountSid, string $authToken): bool
    {
        return Client::validateCredentials($accountSid, $authToken);
    }

    protected function ownsPhoneNumber(string $accountSid, string $authToken, string $phoneNumber): bool
    {
        return Client::ownsPhoneNumber($accountSid, $authToken, $phoneNumber);
    }
}
