<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Odoo\Enums\ConfigurationEnum;
use Kanvas\Connectors\Odoo\Services\OdooApiClient;
use Kanvas\Exceptions\ValidationException;
use Override;

class OdooHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $url = $this->data['url'] ?? null;
        $database = $this->data['db'] ?? null;
        $username = $this->data['username'] ?? null;
        $apiKey = $this->data['api_key'] ?? null;

        if (empty($url) || empty($database) || empty($username) || empty($apiKey)) {
            throw new ValidationException('Odoo url/db/username/api_key are all required for ' . $this->company->name);
        }

        // Never persist credentials Odoo hasn't accepted — same principle as
        // SalesforceHandler::setup() checking describeGlobal() first.
        $uid = OdooApiClient::authenticate(
            (string) $url,
            (string) $database,
            (string) $username,
            (string) $apiKey,
        );

        if ($uid === null) {
            throw new ValidationException('Odoo rejected these credentials for ' . $this->company->name);
        }

        $this->company->set(ConfigurationEnum::URL->value, $url);
        $this->company->set(ConfigurationEnum::DATABASE->value, $database);
        $this->company->set(ConfigurationEnum::USERNAME->value, $username);
        $this->company->set(ConfigurationEnum::API_KEY->value, $apiKey);

        return true;
    }
}
