<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ChromeData\DataTransferObject;

use Spatie\LaravelData\Data;

class VehicleData extends Data
{
    /**
     * @param ColorData[] $exteriorColors
     * @param ColorData[] $interiorColors
     * @param StyleData[] $styles
     */
    public function __construct(
        public readonly ?string $vin,
        public readonly ?int $year,
        public readonly ?string $make,
        public readonly ?string $model,
        public readonly ?string $trim,
        public readonly ?string $styleName,
        public readonly ?string $bodyStyle,
        public readonly ?string $driveTrain,
        public readonly ?int $passDoors,
        public readonly ?string $stockImage,
        public readonly ?EngineData $engine,
        public readonly array $exteriorColors,
        public readonly array $interiorColors,
        public readonly ?PriceData $basePrice,
        public readonly array $styles,
        public readonly ?string $responseStatus,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $exteriorColors = array_map(
            fn (array $color) => ColorData::from($color),
            $data['exteriorColors'] ?? []
        );

        $interiorColors = array_map(
            fn (array $color) => ColorData::from($color),
            $data['interiorColors'] ?? []
        );

        $styles = array_map(
            fn (array $style) => StyleData::from($style),
            $data['styles'] ?? []
        );

        return new self(
            vin: $data['vin'] ?? null,
            year: $data['year'] ?? null,
            make: $data['make'] ?? null,
            model: $data['model'] ?? null,
            trim: $data['trim'] ?? null,
            styleName: $data['styleName'] ?? null,
            bodyStyle: $data['bodyStyle'] ?? null,
            driveTrain: $data['driveTrain'] ?? null,
            passDoors: $data['passDoors'] ?? null,
            stockImage: $data['stockImage'] ?? null,
            engine: $data['engine'] ? EngineData::from($data['engine']) : null,
            exteriorColors: $exteriorColors,
            interiorColors: $interiorColors,
            basePrice: $data['basePrice'] ? PriceData::from($data['basePrice']) : null,
            styles: $styles,
            responseStatus: $data['responseStatus'] ?? null,
        );
    }
}
