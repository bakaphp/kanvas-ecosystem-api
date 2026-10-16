<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use Baka\Support\Str;
use stdClass;

/**
 * `events_versions` → EventVersion columns, custom fields and metadata.
 *
 * Versions were importing with only name/version/price/currency, so the Eventos tab's 35 filters
 * and most of the reports had nothing to read.
 */
class EventVersionMapper
{
    /**
     * `events_versions` FK → [legacy catalog, custom field].
     *
     * These have no Kanvas catalog of their own, so the legacy name is stored. The ones Kanvas
     * *does* model — type, class, category, theme, área, status — are resolved to real Kanvas ids
     * by the action instead.
     */
    public const array LOOKUP_FIELD_MAP = [
        'languages_id' => ['table' => 'languages', 'custom_field' => 'idioma'],
        'affiliates_id' => ['table' => 'affiliates', 'custom_field' => 'aliado'],
        'places_id' => ['table' => 'places', 'custom_field' => 'lugar'],
        'places_areas_id' => ['table' => 'places_areas', 'custom_field' => 'sala'],
        'setup_types_id' => ['table' => 'setup_types', 'custom_field' => 'tipo_montaje'],
    ];

    /**
     * The five "cubierto por" columns. Kanvas has no counterpart and they are display-only on the
     * legacy quote/version screens, so they ride along in metadata rather than becoming filterable
     * custom fields.
     */
    public const array COVERED_BY_COLUMNS = [
        'place_covered_by' => 'lugar',
        'hotel_covered_by' => 'hotel',
        'equip_covered_by' => 'equipos',
        'trip_covered_by' => 'viaje',
        'flight_covered_by' => 'vuelo',
    ];

    /**
     * @return list<string>
     */
    public static function lookupTables(): array
    {
        return array_values(array_unique(array_column(self::LOOKUP_FIELD_MAP, 'table')));
    }

    /**
     * @param array<string, array<int, string>> $lookupNames catalog => [id => name]
     * @param array<int, array{pais: ?string, ciudad: ?string}> $placeGeo places.id => resolved geo
     *
     * @return array<string, mixed>
     */
    public static function customFields(stdClass $row, array $lookupNames = [], array $placeGeo = []): array
    {
        $fields = [];

        foreach (self::LOOKUP_FIELD_MAP as $column => $spec) {
            $id = $row->{$column} ?? null;

            if ($id === null) {
                continue;
            }

            $name = Str::trimToNull((string) ($lookupNames[$spec['table']][(int) $id] ?? ''));

            if ($name !== null) {
                $fields[$spec['custom_field']] = $name;
            }
        }

        // The Eventos tab filters event country and city through the venue
        // (placesareas.places.countries_id), not off the version, so they are resolved here.
        $geo = $placeGeo[(int) ($row->places_id ?? 0)] ?? [];

        // Only nulls dropped — a satisfaction of 0 and an exchange rate of 0 are real values.
        return array_filter([
            ...$fields,
            'pais_evento' => $geo['pais'] ?? null,
            'ciudad_evento' => $geo['ciudad'] ?? null,
            'satisfaccion_facilitadores' => $row->facilitators_satisfaction ?? null,
            'satisfaccion_empresas' => $row->companies_satisfaction ?? null,
            'tasa_cambio' => $row->exchange_rate ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function metadata(stdClass $row): array
    {
        $coveredBy = [];

        foreach (self::COVERED_BY_COLUMNS as $column => $key) {
            $value = $row->{$column} ?? null;

            if ($value !== null) {
                $coveredBy[$key] = (int) $value;
            }
        }

        return array_filter([
            'max_capacity' => $row->max_capacity ?? 0,
            'has_book' => (bool) ($row->has_book ?? false),
            'has_forum' => (bool) ($row->has_forum ?? false),
            'has_graduation' => (bool) ($row->has_graduation ?? false),
            'has_translations' => (bool) ($row->has_translations ?? false),
            'covered_by' => $coveredBy === [] ? null : $coveredBy,
        ], fn ($value) => $value !== null);
    }
}
