<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use Baka\Support\Str;
use stdClass;

class LeadMapper
{
    /**
     * Fallback only — SIPGO status ids are install-specific and drift as rows are added to
     * `quotes_statuses`. Callers resolve the stage from that catalog by name; this is what they
     * fall back to when the catalog has no row for the id.
     */
    public const string DEFAULT_STAGE = 'drafting';

    /**
     * Legacy `quotes_statuses.name` → Kanvas pipeline stage name.
     *
     * Keyed on the catalog name rather than the id, so adding a status in SIPGO no longer silently
     * routes quotes to "drafting". An unmapped name passes through unchanged, which lets a stage
     * named the same on both sides match without an entry here.
     */
    public const array STATUS_NAME_TO_STAGE = [
        'EN ELABORACION' => 'drafting',
        'DESCARTADA' => 'discarded',
        'PENDIENTE DE APROBACION' => 'pending-approval',
        'GANADA' => 'won',
        'PERDIDA' => 'lost',
        'ANULADA' => 'voided',
        'CANCELADA' => 'cancelled',
        'REFERIDA' => 'referred',
        'GANADA PLAN' => 'won-plan',
        'PENDIENTE DE PRESENTACION' => 'pending-presentation',
    ];

    public static function stageForStatusName(?string $statusName): string
    {
        $name = trim((string) $statusName);

        if ($name === '') {
            return self::DEFAULT_STAGE;
        }

        return self::STATUS_NAME_TO_STAGE[mb_strtoupper($name)] ?? $name;
    }

    /**
     * `quotes` FK → [legacy catalog, custom field]. Names, not ids — the ids mean nothing here.
     */
    public const array LOOKUP_FIELD_MAP = [
        'themes_areas_id' => ['table' => 'themes_areas', 'custom_field' => 'area'],
        'languages_id' => ['table' => 'languages', 'custom_field' => 'idioma'],
        'places_id' => ['table' => 'places', 'custom_field' => 'lugar'],
        'places_areas_id' => ['table' => 'places_areas', 'custom_field' => 'sala'],
        'setup_types_id' => ['table' => 'setup_types', 'custom_field' => 'tipo_montaje'],
        'age_ranges_id' => ['table' => 'age_ranges', 'custom_field' => 'rango_edad'],
        'potentialities_id' => ['table' => 'potentialities', 'custom_field' => 'potencialidad'],
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
     */
    public static function fromIntras(stdClass $row, array $lookupNames = []): array
    {
        return [
            'title' => 'Quote #' . ($row->number ?? $row->id),
            // Only nulls dropped — a budget of 0 or zero participants are real values the
            // default array_filter callback would erase.
            'custom_fields' => array_filter([
                'numero' => Str::trimToNull((string) ($row->number ?? '')),
                'quote_amount' => $row->quote_amount ?? null,
                'presupuesto_min' => $row->budget_min ?? null,
                'presupuesto_max' => $row->budget_max ?? null,
                'total_participants' => $row->total_participants ?? null,
                'classification' => $row->classification ?? null,
                'requested_date' => $row->requested_date ?? null,
                'sent_date' => $row->sent_date ?? null,
                'info_objectives' => $row->info_objectives ?? null,
                'info_issues' => $row->info_issues ?? null,
                'en_sede_cliente' => isset($row->is_company_location)
                    ? (bool) $row->is_company_location
                    : null,
                'multiples_lugares' => isset($row->quote_multiple_places)
                    ? (bool) $row->quote_multiple_places
                    : null,
                'tiene_graduacion' => isset($row->has_graduation) ? (bool) $row->has_graduation : null,
                'tiene_traduccion' => isset($row->has_translations) ? (bool) $row->has_translations : null,
                ...self::profileFields($row, $lookupNames),
            ], fn ($value) => $value !== null),
        ];
    }

    /**
     * @param array<string, array<int, string>> $lookupNames
     *
     * @return array<string, string>
     */
    public static function profileFields(stdClass $row, array $lookupNames = []): array
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

        return $fields;
    }
}
