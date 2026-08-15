<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\DataTransferObject;

use Spatie\LaravelData\Data;

class DebidaDiligencia extends Data
{
    public function __construct(
        public bool $politicamenteExpuesto = false,
        public bool $poseeUnFamiliarPep = false,
        public string $cargo = '',
        public string $nombreFamiliar = '',
        public string $parentescoFamiliar = '',
        public string $cargoFamiliar = '',
    ) {
    }
}
