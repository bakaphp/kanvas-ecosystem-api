<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\WebPos\Actions\SetupWebPosAction;
use Kanvas\Connectors\WebPos\Enums\ConfigurationEnum;
use Kanvas\Connectors\WebPos\Enums\EnvironmentEnum;
use Override;

/**
 * Invoked by the generic `integrationCompany` mutation. Same setup as `kanvas:webpos-setup`.
 *
 * Teardown removes the WebPOS API key but leaves `scribe-ecf-provider` alone: that setting belongs to Scribe,
 * and clearing it would silently switch the company back to plain numbering mid-range.
 */
class WebPosHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        new SetupWebPosAction(
            app: $this->app,
            company: $this->company,
            apiKey: (string) ($this->data['api_key'] ?? ''),
            companyLicense: (string) ($this->data['license'] ?? ''),
            branch: (string) ($this->data['branch'] ?? ''),
            pos: (string) ($this->data['pos'] ?? ''),
            issuerRnc: (string) ($this->data['rnc'] ?? ''),
            environment: EnvironmentEnum::tryFrom((string) ($this->data['environment'] ?? '')) ?? EnvironmentEnum::TEST,
        )->execute();

        return true;
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return ConfigurationEnum::companyKeys();
    }
}
