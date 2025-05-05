<?php

declare(strict_types=1);

namespace Kanvas\Connectors\IPlus\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\IPlus\Client;
use Kanvas\Connectors\IPlus\DataTransferObject\IPlus;
use Kanvas\Connectors\IPlus\Services\IPlusSetupService;
use Override;

class IPlusHandler extends BaseIntegration
{
    #[Override]
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
        return ! empty(new Client($this->app, $this->company)->getValidAccessToken());
    }
}
