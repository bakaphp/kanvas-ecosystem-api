<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\DataTransferObject;

use Spatie\LaravelData\Data;

class RentCar extends Data
{
    public function __construct(
        public string $codCategoria,
        public string $codDias,
    ) {
    }
}
