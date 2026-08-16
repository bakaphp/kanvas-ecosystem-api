<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\DataTransferObject;

use Spatie\LaravelData\Data;

class Vehiculo extends Data
{
    public function __construct(
        public int $idModelo,
        public int $anio,
        public bool $esCeroKm,
        public string $combustible,
        public bool $cumpleRequisitos,
        public bool $esUsoParticularNoDeportivoNoPublico,
        public int $valor,
        public string $tipoGas = '',
        public string $tipoInstalacion = '',
        // Required only for A-PC (Por Si Chocas).
        public ?int $sumaAsegurada = null,
        public ?Inspeccion $inspeccion = null,
    ) {
    }
}
