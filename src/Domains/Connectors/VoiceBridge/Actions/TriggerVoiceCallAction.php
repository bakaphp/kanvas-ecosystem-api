<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Connectors\VoiceBridge\Client;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum;
use Kanvas\Connectors\VoiceBridge\Services\VoiceBridgeService;
use Kanvas\Guild\Leads\Models\Lead;

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
     * Build from a Lead, deriving session ID and phone automatically.
     */
    public static function fromLead(Lead $lead): self
    {
        $app = $lead->app;

        $phone = Str::normalizePhoneNumber(
            $lead->people->getCellPhones()->first()?->value
            ?? $lead->people->getAllPhones()->first()?->value
            ?? ''
        );

        $companyId = (string) $app->get(ConfigurationEnum::COMPANY_ID->value);
        $sessionId = VoiceBridgeService::buildOutboundSessionId((string) $lead->getId(), $phone, $companyId);
        $userId = (string) ($lead->leads_owner_id ?: $lead->users_id);

        return new self(
            app: $app,
            sessionId: $sessionId,
            userId: $userId,
            phoneNumber: $phone,
        );
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
