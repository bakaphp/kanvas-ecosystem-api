<?php

declare(strict_types=1);

namespace Kanvas\Domains\Connectors\AeroAmbulancia\DataTransferObject;

abstract class BaseDto
{
    public function __construct(protected array $data)
    {
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
