<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\DataTransferObject;

use Spatie\LaravelData\Data;

class Cliente extends Data
{
    public function __construct(
        public string $nombre = '',
        public string $apellido = '',
        public string $genero = '',
        public ?string $fechaNacimiento = null,
        public string $tipoDocumento = '',
        public string $numeroDocumento = '',
        public string $telefono = '',
        public string $nacionalidad = '',
        public string $correo = '',
        public string $estadoCivil = '',
        public string $ocupacion = '',
        public bool $requiereComprobanteFiscal = false,
        public string $paisResidencia = '',
        public ?string $fechaExpiracionPasaporte = null,
        public ?string $imagenPasaporteUrl = null,
        public ?Direccion $direccion = null,
        public ?DebidaDiligencia $debidaDiligencia = null,
    ) {
    }
}
