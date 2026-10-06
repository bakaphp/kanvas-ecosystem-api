<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Baka\Support\Str;

/**
 * Provider catalog rows come back with whatever key spelling they felt like using for the id and
 * the label. Reading them through one tolerant accessor keeps every caller from re-implementing the
 * same guesswork.
 */
final class RoadsideCatalogEntry
{
    private const ID_KEYS = ['id', 'Id', 'ID', 'codigo', 'Codigo', 'idEstado', 'estadoId'];
    private const LABEL_KEYS = ['nombre', 'Nombre', 'descripcion', 'Descripcion', 'estado', 'Estado', 'name'];
    private const WRAPPER_KEYS = ['data', 'items', 'result', 'estados', 'causas', 'proveedores'];

    public function __construct(
        public readonly int|string|null $id,
        public readonly string $label,
        public readonly array $raw,
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            id: self::firstPresent($row, self::ID_KEYS),
            label: (string) (self::firstPresent($row, self::LABEL_KEYS) ?? ''),
            raw: $row,
        );
    }

    /**
     * @return array<int, self>
     */
    public static function collection(array $rows): array
    {
        // A payload can arrive either as a bare list or wrapped in a named key.
        foreach (self::WRAPPER_KEYS as $wrapper) {
            if (is_array($rows[$wrapper] ?? null)) {
                $rows = $rows[$wrapper];

                break;
            }
        }

        return array_values(array_map(
            static fn (array $row): self => self::fromArray($row),
            array_filter($rows, 'is_array'),
        ));
    }

    public function matches(string $needle): bool
    {
        $needle = Str::slug(trim($needle));

        return $needle !== '' && in_array($needle, [
            Str::slug((string) $this->id),
            Str::slug($this->label),
        ], true);
    }

    private static function firstPresent(array $row, array $keys): int|string|null
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && (is_string($row[$key]) || is_int($row[$key]))) {
                return $row[$key];
            }
        }

        return null;
    }
}
