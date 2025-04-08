<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Recombee\Client;
use Kanvas\Connectors\Recombee\DataTransferObject\RecombeeSetup;
use Kanvas\Connectors\Recombee\Services\RecombeeService;
use Recombee\RecommApi\Requests\ListItems;

class RecombeeHandler extends BaseIntegration
{
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

        RecombeeService::recombeeSetup($recombeeDto);

        return ! empty(
            Client::getInstanceValidation(
                $this->app,
                $this->company,
                $recombeeDto->region
            )->send(new ListItems()));
    }
}
