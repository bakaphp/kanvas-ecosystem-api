<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\DataTransferObject;

use Spatie\LaravelData\Data;

class Direccion extends Data
{
    public function __construct(
        public string $provincia = '',
        public string $municipio = '',
        public string $sector = '',
        public string $edificio = '',
        public string $calle = '',
    ) {
    }
}
