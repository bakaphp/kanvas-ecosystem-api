<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\DataTransferObject;

use Spatie\LaravelData\Data;

class Terminos extends Data
{
    public function __construct(
        public string $seguroLey,
        public bool $asistenciaVehicular,
        public string $autoSustituto,
        public string $cupon = '',
        public ?string $fraccionamientoPago = null,
        public ?string $formaPago = null,
        public ?bool $ceroDeducible = null,
        public ?RentCar $rentCar = null,
    ) {
    }
}
