<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ReadAi\Handlers;

use Baka\Contracts\BaseIntegration;
use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\SetMeetingRoutingAction;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceSettingEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;
use Throwable;

/**
 * Read.ai has no API for creating a webhook: a workspace admin creates it in Read.ai, points it at the
 * company's orchestrator receiver and copies its signing key here. The key is all this stores; the
 * orchestrator's Read.ai adapter checks every request against it.
 *
 * Connecting Read.ai is also what turns on lead and organization routing for the company, and
 * disconnecting or switching it off turns it back to projects only.
 */
class ReadAiHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $signingKey = Str::trimToNull((string) ($this->data['signing_key'] ?? ''));

        if ($signingKey === null) {
            throw new ValidationException('The Read.ai webhook signing key is required.');
        }

        if (base64_decode($signingKey, true) === false) {
            throw new ValidationException('That is not a Read.ai signing key — copy it again from the webhook settings.');
        }

        $this->company->setEncrypted(SignalSourceSettingEnum::READ_AI_SIGNING_KEY->value, $signingKey);

        return true;
    }

    #[Override]
    public function saved(IntegrationsCompany $integrationCompany): void
    {
        if ($integrationCompany->is_active) {
            $this->setMeetingRouting(true);
        }
    }

    #[Override]
    public function activeChanged(IntegrationsCompany $integrationCompany, bool $active): void
    {
        $this->setMeetingRouting($active);
    }

    #[Override]
    public function teardown(): void
    {
        parent::teardown();
        $this->setMeetingRouting(false);
    }

    /**
     * Routing is a convenience on top of the connection: a failure here (an orchestrator that can't
     * be provisioned yet) must not undo the key the admin just saved.
     */
    private function setMeetingRouting(bool $enabled): void
    {
        try {
            new SetMeetingRoutingAction($this->app, $this->company, $enabled)->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return [
            SignalSourceSettingEnum::READ_AI_SIGNING_KEY->value,
        ];
    }
}
