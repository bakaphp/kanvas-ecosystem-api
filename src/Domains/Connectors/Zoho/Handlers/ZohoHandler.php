<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class ZohoHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $clientId = $this->data['client_id'] ?? null;
        $clientSecret = $this->data['client_secret'] ?? null;
        $refreshToken = $this->data['refresh_token'] ?? null;

        if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
            throw new ValidationException('Zoho keys are not set for ' . $this->company->name);
        }

        $this->company->set(CustomFieldEnum::CLIENT_ID->value, $clientId);
        $this->company->set(CustomFieldEnum::CLIENT_SECRET->value, $clientSecret);
        $this->company->set(CustomFieldEnum::REFRESH_TOKEN->value, $refreshToken);

        $zohoClient = Client::getInstance($this->app, $this->company);

        return $zohoClient->leads->getList(['page' => 1, 'per_page' => 1])->pagination()->count() >= 0;
    }
}
