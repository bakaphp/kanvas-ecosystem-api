<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\DataTransferObject;

use Kanvas\Connectors\UniversalSeguros\Concerns\OmitsNulls;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Override;
use Spatie\LaravelData\Data;

class QuoteRequest extends Data
{
    use OmitsNulls;

    public function __construct(
        public string $producto,
        public QuoteData $data,
    ) {
    }

    /**
     * Spatie serialises unset optionals as explicit nulls, and
     * `terminos.ceroDeducible: null` is one of the keys that turns a clean 400 into
     * a bare 500 — see OmitsNulls.
     */
    #[Override]
    public function toArray(): array
    {
        return self::withoutNulls(parent::toArray());
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function make(ProductEnum $product, array $input): self
    {
        // A client's `cupon: null` reaches `string $cupon = ''` and TypeErrors before
        // any of our code runs. Every defaulted scalar in these DTOs has the hazard,
        // so it is disarmed here instead of making nine classes nullable.
        $input = self::withoutNulls($input);

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
