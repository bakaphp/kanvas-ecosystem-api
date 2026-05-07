<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Override;

class TwilioHandlers extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $validated = $this->validateCredentials(
            $this->data['account_sid'] ?? '',
            $this->data['auth_token'] ?? '',
        );
        if (! $validated) {
            throw new \Exception('Failed to validate Twilio connection');
        }
        $this->app->set(ConfigurationEnum::TWILIO_ACCOUNT_SID->value, $this->data['account_sid']);
        $this->app->set(ConfigurationEnum::TWILIO_AUTH_TOKEN->value, $this->data['auth_token']);

        // Handle incoming messages from Twilio
        return true;
    }

    protected function validateCredentials(string $accountSid, string $authToken): bool
    {
        return Client::validateCredentials($accountSid, $authToken);
    }
}
