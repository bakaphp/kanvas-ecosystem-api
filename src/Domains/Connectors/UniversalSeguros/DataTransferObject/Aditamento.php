<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\DataTransferObject;

use Spatie\LaravelData\Data;

class Aditamento extends Data
{
    public function __construct(
        public string $codAditamento,
        public int $montoAditamento,
        public string $comentario = '',
    ) {
    }
}
