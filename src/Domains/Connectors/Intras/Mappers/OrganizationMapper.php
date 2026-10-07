<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use Baka\Support\Str;
use stdClass;

class OrganizationMapper
{
    /**
     * `companies` FK column → [legacy catalog, Organization custom field].
     *
     * Same treatment as the participant lookups: the legacy id means nothing outside SIPGO, so the
     * catalog's name is what gets stored.
     */
    public const array LOOKUP_FIELD_MAP = [
        'business_sectors_id' => ['table' => 'business_sectors', 'custom_field' => 'sector'],
        'business_types_id' => ['table' => 'business_types', 'custom_field' => 'tipo'],
        'business_activities_id' => ['table' => 'business_activities', 'custom_field' => 'actividad'],
        'companies_statuses_id' => ['table' => 'companies_statuses', 'custom_field' => 'estatus'],
    ];

    /**
     * `companies_custom_fields` name → Organization custom field.
     *
     * Tamaño is the Empresas tab's "Tamaño" filter (`companies.custom_fields.tamano`) — a custom
     * field in SIPGO, not a column.
     */
    public const array EXTRA_CUSTOM_FIELD_MAP = [
        'tamano' => 'tamano',
        'telefono' => 'telefono',
    ];

    /**
     * @return list<string>
     */
    public static function lookupTables(): array
    {
        return array_values(array_unique(array_column(self::LOOKUP_FIELD_MAP, 'table')));
    }

    /**
     * @return list<string>
     */
    public static function customFieldNames(): array
    {
        return array_keys(self::EXTRA_CUSTOM_FIELD_MAP);
    }

    /**
     * @param array<string, string> $customFieldRows companies_custom_fields.name => value
     * @param array<string, array<int, string>> $lookupNames catalog => [id => name]
     * @param array<int, string> $companyNames legacy companies.id => name, for `vinculacion`
     */
    public static function fromIntras(
        stdClass $row,
        array $customFieldRows = [],
        array $lookupNames = [],
        array $companyNames = []
    ): array {
        return [
            'name' => trim($row->name),
            // Only nulls may be dropped — the default array_filter callback also eats false and 0,
            // which erased is_prospect/is_supplier and every zeroed counter.
            'custom_fields' => array_filter([
                'rnc' => $row->rnc ?? null,
                'classification' => $row->classification ?? null,
                'clasificacion_abierta' => $row->classification_open ?? null,
                'potencialidad' => $row->potentiality ?? null,
                'intras_is_prospect' => (bool) ($row->is_prospect ?? false),
                'es_suplidor' => isset($row->is_supplier) ? (bool) $row->is_supplier : null,
                'total_employees' => $row->total_employees ?? null,
                'cotizaciones_total' => $row->quotes_count ?? null,
                'cotizaciones_ganadas' => $row->quotes_won ?? null,
                'rango_inversion' => $row->quotes_investment_range ?? null,
                // "Vinculación Empresarial" — a self-FK to the parent company. Stored as the name
                // so the value survives without the legacy id space.
                'vinculacion' => self::linkedCompanyName($row, $companyNames),
                ...self::profileFields($row, $customFieldRows, $lookupNames),
            ], fn ($value) => $value !== null),
        ];
    }

    private static function linkedCompanyName(stdClass $row, array $companyNames): ?string
    {
        $linkedId = (int) ($row->linked_companies_id ?? 0);

        return $linkedId === 0
            ? null
            : Str::trimToNull((string) ($companyNames[$linkedId] ?? ''));
    }

    /**
     * @param array<string, string> $customFieldRows
     * @param array<string, array<int, string>> $lookupNames
     *
     * @return array<string, string>
     */
    public static function profileFields(
        stdClass $row,
        array $customFieldRows = [],
        array $lookupNames = []
    ): array {
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

        foreach (self::EXTRA_CUSTOM_FIELD_MAP as $legacyName => $kanvasName) {
            $value = Str::trimToNull((string) ($customFieldRows[$legacyName] ?? ''));

            if ($value !== null) {
                $fields[$kanvasName] = $value;
            }
        }

        return $fields;
    }
}
