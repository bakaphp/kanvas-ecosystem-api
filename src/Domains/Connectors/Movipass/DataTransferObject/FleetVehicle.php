<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\DataTransferObject;

use Spatie\LaravelData\Data;

class FleetVehicle extends Data
{
    public function __construct(
        public readonly string $tagNumber,
        public readonly string $brand,
        public readonly ?string $model = null,
        public readonly ?string $year = null,
        public readonly ?string $plate = null,
        public readonly ?string $vin = null,
    ) {
    }

    public static function fromImportArray(array $row): self
    {
        [$brand, $model] = self::resolveBrandAndModel($row);

        return new self(
            tagNumber: trim((string) ($row['tag_number'] ?? $row['tag'] ?? '')),
            brand: $brand,
            model: $model,
            year: self::stringOrNull($row['year'] ?? null),
            plate: self::stringOrNull($row['plate'] ?? null),
            vin: self::stringOrNull($row['vin'] ?? null),
        );
    }

    /**
     * Accept either explicit brand/model columns or a single combined "marca"
     * column (the shape the PDF ships, e.g. "KIA PICANTO") and split it on the
     * first whitespace — first token is the brand, the rest is the model.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function resolveBrandAndModel(array $row): array
    {
        $brand = trim((string) ($row['brand'] ?? ''));
        $model = trim((string) ($row['model'] ?? ''));

        if ($brand !== '') {
            return [$brand, $model !== '' ? $model : null];
        }

        $marca = trim((string) ($row['marca'] ?? $row['make'] ?? ''));

        if ($marca === '') {
            return ['', null];
        }

        $parts = preg_split('/\s+/', $marca, 2) ?: [$marca];

        return [$parts[0], $parts[1] ?? null];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
