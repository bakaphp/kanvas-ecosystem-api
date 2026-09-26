<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\DataTransferObject;

use Kanvas\Connectors\Humano\Enums\DatoEnum;

/**
 * One entry in Humano's generic `datos[]` bag. `numeroBien` identifies the insured
 * asset within the quote; auto quotes carry a single vehicle, so it is 1.
 */
class Dato
{
    public function __construct(
        public readonly DatoEnum $dato,
        public readonly string $value,
        public readonly int $numeroBien = 1,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
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
