<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Handlers;

use Baka\Support\Str;
use Kanvas\Connectors\Alanube\Actions\ConnectAlanubeCompanyAction;
use Kanvas\Connectors\Alanube\Enums\AccountModeEnum;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Override;

/**
 * Invoked by the generic `integrationCompany` mutation to connect a company that already exists at Alanube
 * (same as `kanvas:alanube-setup` without a certificate). Onboarding a new company with its certificate is the
 * command's job: a certificate must never travel through a stored integration config.
 *
 * Teardown leaves `scribe-ecf-provider` alone, as WebPOS does: it belongs to Scribe, and clearing it would
 * switch the company back to plain numbering mid-range.
 */
class AlanubeHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $mode = AccountModeEnum::tryFrom((string) ($this->data['account_mode'] ?? AccountModeEnum::OWN->value))
            ?? throw new ValidationException('Alanube account_mode must be kanvas or own.');

        new ConnectAlanubeCompanyAction(
            app: $this->app,
            company: $this->company,
            mode: $mode,
            token: Str::trimToNull((string) ($this->data['api_token'] ?? '')),
            alanubeCompanyId: Str::trimToNull((string) ($this->data['alanube_company_id'] ?? '')),
        )->execute();

        return true;
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return ConfigurationEnum::companyKeys();
    }
}
