<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Connectors\Zoho\DataTransferObject\ZohoSetup;
use Kanvas\Connectors\Zoho\ZohoService;

class ZohoHandler extends BaseIntegration
{
    public function setup(): bool
    {
        $zohoDto = new ZohoSetup(
            company: $this->company,
            app: $this->app,
            region: $this->region,
            clientId: $this->data['client_id'],
            clientSecret: $this->data['client_secret'],
        );

        ZohoService::zohoSetup($zohoDto);

        return ! empty(Client::getInstanceValidation($this->app, $this->company, $zohoDto->region)->leads->getList());
    }
}
