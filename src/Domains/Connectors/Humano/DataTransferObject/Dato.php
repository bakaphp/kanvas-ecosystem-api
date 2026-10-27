<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\DataTransferObject;

use Kanvas\Connectors\Humano\Enums\DatoEnum;

class Dato
{
    public function __construct(
        public readonly DatoEnum $dato,
        public readonly string $value,
        public readonly int $numeroBien = 1,
    ) {
    }

    public function toArray(): array
    {
        return [
            'codigoDato' => $this->dato->value,
            'valorDato' => $this->value,
            'label' => $this->dato->label(),
            'numeroBien' => $this->numeroBien,
        ];
    }
}
