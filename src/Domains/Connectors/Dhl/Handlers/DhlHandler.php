<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Dhl\Actions\BuildDhlRatesPayloadAction;
use Kanvas\Connectors\Dhl\Client;
use Kanvas\Connectors\Dhl\DataTransferObject\Dhl;
use Kanvas\Connectors\Dhl\Enums\ConfigurationEnum;
use Override;
use Throwable;

class DhlHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $dhl = Dhl::viaRequest(
            data: $this->data,
            app: $this->app,
            company: $this->company,
            region: $this->region
        );

        $this->company->set(ConfigurationEnum::BASE_URL->value, $dhl->baseUrl);
        $this->company->set(ConfigurationEnum::USERNAME->value, $dhl->username);
        $this->company->setEncrypted(ConfigurationEnum::PASSWORD->value, $dhl->password);
        $this->company->set(ConfigurationEnum::ACCOUNT_NUMBER->value, $dhl->accountNumber);
        $this->company->set(ConfigurationEnum::SIGNATURE_SERVICE->value, $dhl->signatureService);
        $this->company->set(ConfigurationEnum::LABEL_TEMPLATE->value, $dhl->labelTemplate);
        $this->company->set(ConfigurationEnum::ORIGIN->value, $dhl->origin);
        $this->company->set(ConfigurationEnum::REQUEST_PICKUP->value, $dhl->requestPickup ? 1 : 0);
        $this->company->set(ConfigurationEnum::PICKUP_CLOSE_TIME->value, $dhl->pickupCloseTime);

        if ($dhl->contentDescription !== null) {
            $this->company->set(ConfigurationEnum::CONTENT_DESCRIPTION->value, $dhl->contentDescription);
        }

        return $this->credentialsAreValid($dhl);
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return array_map(fn (ConfigurationEnum $key): string => $key->value, ConfigurationEnum::cases());
    }

    private function credentialsAreValid(Dhl $dhl): bool
    {
        $probe = new BuildDhlRatesPayloadAction(
            company: $this->company,
            parcels: [['weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 10]],
            destination: $dhl->origin,
        );

        try {
            new Client($this->company)->rates($probe->execute());

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
