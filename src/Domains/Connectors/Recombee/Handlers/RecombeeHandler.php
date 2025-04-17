<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Recombee\Client;
use Kanvas\Connectors\Recombee\DataTransferObject\RecombeeSetup;
use Kanvas\Connectors\Recombee\Services\RecombeeService;
use Override;
use Recombee\RecommApi\Requests\ListItems;

class RecombeeHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $recombeeDto = new RecombeeSetup(
            company: $this->company,
            app: $this->app,
            region: $this->region,
            recombeeDatabase: $this->data['database_id'],
            privateToken: $this->data['private_token'],
            recombeeRegion: $this->data['recombee_region']
        );

        RecombeeService::setup($recombeeDto);

        return ! empty(new Client($this->app)->getClient()->send(new ListItems()));
    }
}
