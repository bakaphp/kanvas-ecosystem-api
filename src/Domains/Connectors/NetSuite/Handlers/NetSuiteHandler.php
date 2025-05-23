<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Handlers;

use Baka\Support\Str;
use Exception;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\NetSuite\DataTransferObject\NetSuite as NetSuiteDto;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteServices;
use Override;

class NetSuiteHandler extends BaseIntegration
{
    #[Override]
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
        $customer = new NetSuiteCustomerService(
            app: $this->app,
            company: $this->company,
        );

        try {
            $customer->getCustomerById('1');
        } catch (Exception $e) {
            if (Str::contains($e->getMessage(), 'Error retrieving customer:')) {
                return true;
            }

            return false;
        }

        return true;
    }
}
