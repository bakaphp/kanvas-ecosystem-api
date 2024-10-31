<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Handlers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Interfaces\IntegrationInterfaces;
use Kanvas\Connectors\NetSuite\DataTransferObject\NetSuite as NetSuiteDto;
use Kanvas\Connectors\NetSuite\Services\NetSuiteServices;
use Kanvas\Inventory\Regions\Models\Regions;

class NetSuiteHandler extends IntegrationInterfaces
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public Regions $region,
        public array $data
    ) {
    }

    public function setup(): bool
    {
        $netSuiteDto = new NetSuiteDto(
            company: $this->company,
            app: $this->app,
            account: $this->data['account'],
            consumerKey: $this->data['consumerKey'],
            consumerSecret: $this->data['consumerSecret'],
            token: $this->data['token'],
            tokenSecret: $this->data['tokenSecret'],
        );

        NetSuiteServices::setup($netSuiteDto);
        $client = new NetSuiteServices($this->app, $this->company);
        return ! empty($client->findExistingCustomer($this->company->email));
    }
}
