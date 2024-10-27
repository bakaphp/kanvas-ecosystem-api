<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Handlers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Interfaces\IntegrationInterfaces;
use Kanvas\Connectors\NetSuite\DataTransferObject\NetSuite as NetSuiteDto;
use Kanvas\Connectors\NetSuite\Services\NetSuiteServices;

class NetSuiteHandler extends IntegrationInterfaces
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public array $data
    ) {
    }

    public function setup(): bool
    {
        $netSuiteDto = new NetSuiteDto(
            company: $this->company,
            app: $this->app,
            endpoint: $this->data['endpoint'],
            apiUrl: $this->data['apiUrl'],
            account: $this->data['account'],
            consumerKey: $this->data['consumerKey'],
            consumerSecret: $this->data['consumerSecret'],
            token: $this->data['token'],
            tokenSecret: $this->data['tokenSecret'],
        );

        NetSuiteServices::netSuitSetup($netSuiteDto);
        $client = new NetSuiteServices($this->app, $this->company);
        return ! empty($client->findExistingCustomer($this->company->email));
    }
}
