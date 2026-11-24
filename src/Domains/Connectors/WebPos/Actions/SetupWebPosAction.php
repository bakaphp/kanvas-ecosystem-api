<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Actions;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WebPos\Client;
use Kanvas\Connectors\WebPos\Enums\ConfigurationEnum;
use Kanvas\Connectors\WebPos\Enums\EnvironmentEnum;
use Kanvas\Connectors\WebPos\Enums\HostEnum;
use Kanvas\Connectors\WebPos\Exceptions\WebPosRequestException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfProviderEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;

/**
 * Stores the WebPOS API key and point of sale, makes WebPOS the company's e-CF provider, and proves the key with
 * an RNC lookup of the issuer, which issues nothing. A lookup proves the key, not the license's permission to
 * issue: WebPOS grants that separately and refuses a send without it (E000).
 */
class SetupWebPosAction
{
    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly string $apiKey,
        public readonly string $companyLicense,
        public readonly string $branch,
        public readonly string $pos,
        public readonly string $issuerRnc,
        public readonly EnvironmentEnum $environment = EnvironmentEnum::TEST,
    ) {
    }

    public function execute(): void
    {
        $required = [
            'API key' => $this->apiKey,
            'license' => $this->companyLicense,
            'branch' => $this->branch,
            'pos' => $this->pos,
        ];

        foreach ($required as $field => $value) {
            if (trim($value) === '') {
                throw new ValidationException("WebPOS {$field} is required.");
            }
        }

        $rnc = Str::digitsOnly($this->issuerRnc);
        if (! DrFiscalRegime::isTaxId($rnc)) {
            throw new ValidationException('The issuer RNC must be the 9-digit RNC (or 11-digit cédula) DGII authorized for e-CF.');
        }

        $this->company->setEncrypted(ConfigurationEnum::API_KEY->value, trim($this->apiKey));
        $this->company->set(ConfigurationEnum::COMPANY_LICENSE->value, trim($this->companyLicense));
        $this->company->set(ConfigurationEnum::BRANCH->value, trim($this->branch));
        $this->company->set(ConfigurationEnum::POS->value, trim($this->pos));
        $this->company->set(ConfigurationEnum::APP_ID->value, $this->app->getId());
        $this->company->set(EcfConfigurationEnum::ECF_ENVIRONMENT->value, $this->environment->value);
        $this->company->set(EcfConfigurationEnum::ECF_PROVIDER->value, EcfProviderEnum::WEBPOS->value);
        $this->company->set(EcfConfigurationEnum::ECF_ISSUER_TAX_ID->value, $rnc);

        foreach (ConfigurationEnum::LEGACY_LOGIN_KEYS as $key) {
            $this->company->del($key);
        }

        try {
            new Client($this->company)->get(HostEnum::ISSUING, 'api/fedo/ak/v1/checkRnc/' . Client::API_KEY . '/' . $rnc);
        } catch (WebPosRequestException $e) {
            throw new ValidationException("WebPOS did not accept the API key (HTTP {$e->status}). Check the key WebPOS issued for this license.");
        }
    }
}
