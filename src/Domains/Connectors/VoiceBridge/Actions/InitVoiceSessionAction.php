<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Connectors\VoiceBridge\Client;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum;
use Kanvas\Connectors\VoiceBridge\Services\VoiceBridgeService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;

class InitVoiceSessionAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly string $sessionId,
        protected readonly string $userId,
        protected readonly array $initialContext = [],
    ) {
    }

    /**
     * Build from a Lead and Agent, deriving session ID and context automatically.
     */
    public static function fromLead(Lead $lead, Agent $agent): self
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

        $initialContext = new BuildLeadVoiceContextAction($lead, $agent)->execute();

        return new self(
            app: $app,
            sessionId: $sessionId,
            userId: $userId,
            initialContext: $initialContext,
        );
    }

    /**
     * Initialize a voice session in VoiceBridge.
     * If the session already exists, returns the current state without overwriting it.
     *
     * @return array{status: string, message: string, state: array}
     */
    public function execute(): array
    {
        return Client::getInstance($this->app)
            ->initSession($this->userId, $this->sessionId, $this->initialContext);
    }
}
