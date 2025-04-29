<?php

declare(strict_types=1);

namespace Kanvas\Domains\Connectors\AeroAmbulancia\Services;

use Kanvas\Domains\Connectors\AeroAmbulancia\Client;
use Kanvas\Domains\Connectors\AeroAmbulancia\DataTransferObject\BaseDto;

abstract class BaseService
{
    protected Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    protected function createDto(array $data, string $dtoClass): BaseDto
    {
        return new $dtoClass($data);
    }
}
