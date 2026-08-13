<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\DataTransferObject;

use Spatie\LaravelData\Data;

class EndosoCesion extends Data
{
    public function __construct(
        public string $institucionFinanciera,
        public string $sucursalInstitucionFinanciera,
        public string $nombreEjecutivoInstitucionFinanciera,
        public string $correoEjecutivoInstitucionFinanciera,
        public string $telefonoEjecutivoInstitucionFinanciera,
    ) {
    }
}
