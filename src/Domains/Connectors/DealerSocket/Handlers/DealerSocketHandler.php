<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\DealerSocket\DataTransferObject\DealerSocket;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketConfigurationService;
use Override;

class DealerSocketHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $dealerSocketDto = new DealerSocket(
            company: $this->company,
            app: $this->app,
            region: $this->region,
            publicKey: $this->data['publicKey'],
            privateKey: $this->data['privateKey'],
            username: $this->data['username'],
            password: $this->data['password'],
            dealerId: $this->data['dealerId'],
            vendorName: $this->data['vendorName'],
        );

        return DealerSocketConfigurationService::setup($dealerSocketDto);
    }
}
