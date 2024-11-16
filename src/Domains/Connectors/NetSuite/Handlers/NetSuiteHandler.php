<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\NetSuite\DataTransferObject\NetSuite as NetSuiteDto;
use Kanvas\Connectors\NetSuite\Services\NetSuiteServices;

class NetSuiteHandler extends BaseIntegration
{
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
