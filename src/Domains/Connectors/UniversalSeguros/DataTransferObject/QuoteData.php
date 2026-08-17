<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\DataTransferObject;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class QuoteData extends Data
{
    /**
     * @param array<int, Aditamento> $aditamentos
     */
    public function __construct(
        public string $tipo,
        public ?Vehiculo $vehiculo = null,
        public ?Cliente $cliente = null,
        public ?string $requestId = null,
        public ?EndosoCesion $endosoCesion = null,
        #[DataCollectionOf(Aditamento::class)]
        public array $aditamentos = [],
        public ?Terminos $terminos = null,
    ) {
    }
}
