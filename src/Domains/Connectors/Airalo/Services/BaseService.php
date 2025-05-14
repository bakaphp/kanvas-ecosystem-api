<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Airalo\Services;

use Kanvas\Connectors\Airalo\Client;
use Kanvas\Connectors\Airalo\DataTransferObject\BaseDto;

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