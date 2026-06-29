<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\DataTransferObject;

use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Spatie\LaravelData\Data;

class QuoteRequest extends Data
{
    public function __construct(
        public string $producto,
        public QuoteData $data,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function make(ProductEnum $product, array $input): self
    {
        $vehiculo = isset($input['vehiculo']) ? Vehiculo::from($input['vehiculo']) : null;
        $cliente = isset($input['cliente']) ? Cliente::from($input['cliente']) : null;
        $terminos = isset($input['terminos']) ? Terminos::from($input['terminos']) : null;
        $endoso = isset($input['endosoCesion']) ? EndosoCesion::from($input['endosoCesion']) : null;

        $aditamentos = array_map(
            fn (array $a): Aditamento => Aditamento::from($a),
            $input['aditamentos'] ?? []
        );

        return new self(
            producto: $product->value,
            data: new QuoteData(
                tipo: (string) ($input['tipo'] ?? $product->defaultTipo()),
                vehiculo: $vehiculo,
                cliente: $cliente,
                requestId: $input['requestId'] ?? null,
                endosoCesion: $endoso,
                aditamentos: $aditamentos,
                terminos: $terminos,
            ),
        );
    }
}
