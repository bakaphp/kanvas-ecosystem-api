<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\VoiceBridge\Client;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum;

class TriggerVoiceCallAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly string $sessionId,
        protected readonly string $userId,
        protected readonly string $phoneNumber,
    ) {
    }

    /**
     * Trigger an outbound call via VoiceBridge.
     * The VoiceBridge company_id is resolved from the app configuration.
     * If a session already had a previous call, VoiceBridge automatically reactivates it.
     *
     * @return array{status: string, message: string, call_sid: string, company_id: string}
     */
    public function execute(): array
    {
        $companyId = $this->app->get(ConfigurationEnum::COMPANY_ID->value);

        return Client::getInstance($this->app)
            ->triggerCall(
                userId: $this->userId,
                sessionId: $this->sessionId,
                phoneNumber: $this->phoneNumber,
                companyId: $companyId ?: null,
            );
    }
}
