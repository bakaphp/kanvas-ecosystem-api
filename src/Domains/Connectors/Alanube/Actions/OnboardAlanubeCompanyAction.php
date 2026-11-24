<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Client;
use Kanvas\Connectors\Alanube\DataTransferObject\AlanubeCertificate;
use Kanvas\Connectors\Alanube\DataTransferObject\AlanubeCompanyProfile;
use Kanvas\Connectors\Alanube\DataTransferObject\AlanubeWebhookRegistration;
use Kanvas\Connectors\Alanube\Enums\AccountModeEnum;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfProviderEnum;
use SensitiveParameter;

/**
 * Creates the tenant at Alanube (`POST /company`) with its DGII certificate and its webhook block, so it is
 * born able to issue and to notify us. In kanvas mode it is an associated company of our main company.
 *
 * The certificate goes straight through: it is in memory for this one request and Kanvas stores only the
 * company id Alanube returns. A renewal is a new upload through this same path. Nothing about the request is
 * logged — a failure surfaces Alanube's answer only (AlanubeRequestException never carries the body).
 */
class OnboardAlanubeCompanyAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly AlanubeCompanyProfile $profile,
        #[SensitiveParameter]
        private readonly AlanubeCertificate $certificate,
        private readonly ?string $logoBase64 = null,
    ) {
    }

    public function execute(): AlanubeWebhookRegistration
    {
        if ($this->company->get(ConfigurationEnum::COMPANY_ID->value) !== null) {
            throw new ValidationException(
                'This company is already onboarded at Alanube. Reconnect it instead; a certificate renewal is '
                . 'uploaded with PATCH, not a second company.'
            );
        }

        $client = new Client($this->app, $this->company);
        $register = new RegisterAlanubeWebhooksAction($this->app, $this->company);

        $body = $this->profile->toCompanyBody();
        if ($client->mode === AccountModeEnum::KANVAS) {
            $body['type'] = 'associated';
        }
        $body['certificate'] = $this->certificate->toPayload();

        $webhooks = $register->webhooksBlock();
        if ($webhooks !== []) {
            $body['webhooks'] = $webhooks;
        }

        if ($this->logoBase64 !== null) {
            $body['logo'] = $this->logoBase64;
        }

        $response = $client->post('company', $body);
        unset($body);

        $record = Client::companyRecord($response);
        $alanubeId = (string) ($record['id'] ?? '');

        if ($alanubeId === '') {
            throw new ValidationException('Alanube created the company but returned no id.');
        }

        $this->company->set(ConfigurationEnum::COMPANY_ID->value, $alanubeId);
        $this->company->set(ConfigurationEnum::APP_ID->value, $this->app->getId());
        $this->company->set(ConfigurationEnum::SENDER_PROFILE->value, $this->profile->toArray());
        $this->company->set(EcfConfigurationEnum::ECF_PROVIDER->value, EcfProviderEnum::ALANUBE->value);

        return $register->execute();
    }
}
