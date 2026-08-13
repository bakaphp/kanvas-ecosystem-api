<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use stdClass;

class ParticipantMapper
{
    /**
     * Legacy SIPGO custom_fields.name → Kanvas ContactTypeEnum.
     *
     * The SIPGO participants table has no email/phone columns; those live in
     * participants_custom_fields keyed by custom_fields.name. `weight` is the
     * Contact tiebreaker — primary office values weight 0, secondaries 1.
     */
    public const array CONTACT_FIELD_MAP = [
        'email_oficina' => ['type' => ContactTypeEnum::EMAIL, 'weight' => 0],
        'email_personal' => ['type' => ContactTypeEnum::SECONDARY_EMAIL, 'weight' => 0],
        'email_asistente' => ['type' => ContactTypeEnum::SECONDARY_EMAIL, 'weight' => 1],
        'celular_1' => ['type' => ContactTypeEnum::CELLPHONE, 'weight' => 0],
        'celular_2' => ['type' => ContactTypeEnum::CELLPHONE, 'weight' => 1],
        'telefono_oficina_1' => ['type' => ContactTypeEnum::WORK_PHONE, 'weight' => 0],
        'telefono_oficina_2' => ['type' => ContactTypeEnum::WORK_PHONE, 'weight' => 1],
        'telefono_casa' => ['type' => ContactTypeEnum::PHONE, 'weight' => 0],
    ];

    /**
     * Custom-field names whose values stay on People as custom fields
     * (don't fit ContactTypeEnum).
     */
    public const array EXTRA_CUSTOM_FIELD_MAP = [
        'ext_1' => 'intras_ext_1',
        'ext_2' => 'intras_ext_2',
    ];

    /**
     * `participants` FK column → [legacy lookup table, People custom-field name].
     *
     * Nivel and área are lookup rows in SIPGO, not text on the participant, so the
     * importer resolves the id to the lookup's `name` and stores that — the legacy
     * ids mean nothing to a Kanvas export.
     */
    public const array LOOKUP_FIELD_MAP = [
        'participants_levels_id' => ['table' => 'participants_levels', 'custom_field' => 'nivel'],
        'themes_areas_id' => ['table' => 'themes_areas', 'custom_field' => 'area'],
        'departments_id' => ['table' => 'departments', 'custom_field' => 'department'],
    ];

    /**
     * @param array<string, string> $contactRows custom_fields.name => value, as
     *                                            preloaded from participants_custom_fields
     * @param array<string, array<int, string>> $lookupNames lookup table => [id => name]
     */
    public static function fromIntras(stdClass $row, array $contactRows = [], array $lookupNames = []): array
    {
        return [
            'firstname' => trim($row->first_name),
            'lastname' => trim($row->last_name),
            'custom_fields' => array_filter([
                'position' => $row->position ?? null,
                'identification' => $row->identification ?? null,
                'intras_is_prospect' => (bool) ($row->is_prospect ?? false),
                'intras_classification' => $row->classification ?? null,
                ...self::profileFields($row, $contactRows, $lookupNames),
            ]),
            'contacts' => self::contactsFromCustomFields($contactRows),
        ];
    }

    /**
     * @return list<string>
     */
    public static function lookupTables(): array
    {
        return array_values(array_unique(array_column(self::LOOKUP_FIELD_MAP, 'table')));
    }

    /**
     * @param array<string, string> $contactRows
     *
     * @return list<array{type: ContactTypeEnum, value: string, weight: int}>
     */
    public static function contactsFromCustomFields(array $contactRows): array
    {
        $contacts = [];

        foreach (self::CONTACT_FIELD_MAP as $name => $spec) {
            $value = trim((string) ($contactRows[$name] ?? ''));
            if ($value === '') {
                continue;
            }

            $contacts[] = [
                'type' => $spec['type'],
                'value' => $value,
                'weight' => $spec['weight'],
            ];
        }

        return $contacts;
    }

    /**
     * @param array<string, string> $contactRows
     * @param array<string, array<int, string>> $lookupNames lookup table => [id => name]
     *
     * @return array<string, string>
     */
    public static function profileFields(stdClass $row, array $contactRows = [], array $lookupNames = []): array
    {
        $fields = [];

        foreach (self::EXTRA_CUSTOM_FIELD_MAP as $legacyName => $kanvasName) {
            $value = trim($contactRows[$legacyName] ?? '');
            if ($value !== '') {
                $fields[$kanvasName] = $value;
            }
        }

        foreach (self::LOOKUP_FIELD_MAP as $column => $spec) {
            $id = $row->{$column} ?? null;
            if ($id === null) {
                continue;
            }

            $name = trim($lookupNames[$spec['table']][(int) $id] ?? '');
            if ($name !== '') {
                $fields[$spec['custom_field']] = $name;
            }
        }

        return $fields;
    }

    /**
     * Names to fetch from custom_fields when bulk-loading contact rows for a
     * batch of participants.
     *
     * @return list<string>
     */
    public static function contactFieldNames(): array
    {
        return [
            ...array_keys(self::CONTACT_FIELD_MAP),
            ...array_keys(self::EXTRA_CUSTOM_FIELD_MAP),
        ];
    }
}
