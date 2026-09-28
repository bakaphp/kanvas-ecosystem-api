<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\DataTransferObject;

use Spatie\LaravelData\Data;

class Inspeccion extends Data
{
    public function __construct(
        public string $tipo,
        public string $placa,
        public string $color,
        public int $cilindraje,
        public int $puertas,
        public string $chasis,
        public string $motor,
    ) {
    }
}
