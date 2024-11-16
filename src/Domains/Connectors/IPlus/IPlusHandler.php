<?php

declare(strict_types=1);

namespace Kanvas\Connectors\IPlus;

use IPlusSetupService;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\NetSuite\DataTransferObject\IPlus;
use League\OAuth2\Client\Token\AccessTokenInterface;

class IPlusHandler extends BaseIntegration
{
    public function setup(): bool
    {
        $iPlus = new IPlus(
            company: $this->company,
            app: $this->app,
            client_id: $this->data['client_id'],
            client_secret: $this->data['client_secret']
        );

        //setup
        IPlusSetupService::setup($iPlus);

        //test connection
        return (new Client($this->app, $this->company))->getValidAccessToken() instanceof AccessTokenInterface;
    }
}
