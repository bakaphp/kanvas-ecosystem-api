<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Client;
use Kanvas\Connectors\Alanube\DataTransferObject\AlanubeCompanyProfile;
use Kanvas\Connectors\Alanube\DataTransferObject\AlanubeWebhookRegistration;
use Kanvas\Connectors\Alanube\Enums\AccountModeEnum;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfProviderEnum;
use SensitiveParameter;

/**
 * Connects a company that already exists at Alanube: its own account (own mode) or an associated company
 * under our main company (kanvas mode). Reads the company back from Alanube to prove the credentials and to
 * take the DGII sender block from the source, registers the webhooks, then makes Alanube the company's e-CF
 * provider. Re-running it is a reconnect.
 */
class ConnectAlanubeCompanyAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly AccountModeEnum $mode,
        #[SensitiveParameter]
        private readonly ?string $token = null,
        private readonly ?string $alanubeCompanyId = null,
    ) {
    }

    public function execute(): AlanubeWebhookRegistration
    {
        if ($this->mode === AccountModeEnum::OWN && $this->token === null
            && $this->company->get(ConfigurationEnum::API_TOKEN->value) === null) {
            throw new ValidationException('Alanube own mode needs the company\'s Alanube API token.');
        }

        if ($this->mode === AccountModeEnum::KANVAS && $this->alanubeCompanyId === null
            && $this->company->get(ConfigurationEnum::COMPANY_ID->value) === null) {
            throw new ValidationException('Alanube kanvas mode needs the associated company id, or onboard the company first.');
        }

        $this->company->set(ConfigurationEnum::ACCOUNT_MODE->value, $this->mode->value);
        $this->company->set(ConfigurationEnum::APP_ID->value, $this->app->getId());

        if ($this->token !== null) {
            $this->company->setEncrypted(ConfigurationEnum::API_TOKEN->value, $this->token);
        }

        if ($this->alanubeCompanyId !== null) {
            $this->company->set(ConfigurationEnum::COMPANY_ID->value, $this->alanubeCompanyId);
        }

        $client = new Client($this->app, $this->company);
        $response = $client->get($client->companyPath())
            ?? throw new ValidationException('Alanube does not know this company. Check the token or company id.');

        $record = Client::companyRecord($response);

        if (! empty($record['id'])) {
            $this->company->set(ConfigurationEnum::COMPANY_ID->value, (string) $record['id']);
        }

        $profile = AlanubeCompanyProfile::fromAlanube($record);
        if ($profile->rnc !== '') {
            $this->company->set(ConfigurationEnum::SENDER_PROFILE->value, $profile->toArray());
            $this->company->set(EcfConfigurationEnum::ECF_ISSUER_TAX_ID->value, $profile->rnc);
        }

        $registration = new RegisterAlanubeWebhooksAction($this->app, $this->company)->execute();

        $this->company->set(EcfConfigurationEnum::ECF_PROVIDER->value, EcfProviderEnum::ALANUBE->value);

        return $registration;
    }
}
