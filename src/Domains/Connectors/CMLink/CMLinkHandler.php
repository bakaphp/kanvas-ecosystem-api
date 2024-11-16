<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink;

use Domains\Connectors\CMLink\Client;
use Kanvas\Connectors\CMLink\DataTransferObject\CMLink;
use Kanvas\Connectors\CMLink\Services\CMLinkSetupService;
use Kanvas\Connectors\Contracts\BaseIntegration;

class CMLinkHandler extends BaseIntegration
{
    public function setup(): bool
    {
        $cmLink = new CMLink(
            company: $this->company,
            app: $this->app,
            app_key: $this->data['app_key'],
            app_secret: $this->data['app_secret'],
            app_account_id: $this->data['app_id'],
            app_account_type: $this->data['app_type']
        );

        //setup
        CMLinkSetupService::setup($cmLink);

        //test connection
        return is_array((new Client($this->app, $this->company))->getAccessToken());
    }
}
