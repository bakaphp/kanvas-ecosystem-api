<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use stdClass;

class FacilitatorMapper
{
    /**
     * `facilitators_custom_fields` name → Kanvas contact type.
     *
     * None of a facilitator's 30 custom fields were being imported, so every facilitator landed
     * with no email and no phone. Mirrors ParticipantMapper::CONTACT_FIELD_MAP.
     */
    public const array CONTACT_FIELD_MAP = [
        'email' => ['type' => ContactTypeEnum::EMAIL, 'weight' => 0],
        'email_2' => ['type' => ContactTypeEnum::SECONDARY_EMAIL, 'weight' => 0],
        'telefono' => ['type' => ContactTypeEnum::PHONE, 'weight' => 0],
        'celular' => ['type' => ContactTypeEnum::CELLPHONE, 'weight' => 0],
    ];

    /**
     * `facilitators` FK column → [legacy catalog, custom field].
     */
    public const array LOOKUP_FIELD_MAP = [
        'facilitators_statuses_id' => ['table' => 'facilitators_statuses', 'custom_field' => 'estatus'],
        'affiliates_id' => ['table' => 'affiliates', 'custom_field' => 'aliado'],
        'home_countries_id' => ['table' => 'countries', 'custom_field' => 'pais'],
        'home_cities_id' => ['table' => 'cities', 'custom_field' => 'ciudad'],
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
    public static function contactFieldNames(): array
    {
        return array_keys(self::CONTACT_FIELD_MAP);
    }

    /**
     * @param array<string, string> $contactRows facilitators_custom_fields.name => value
     * @param array<string, array<int, string>> $lookupNames catalog => [id => name]
     */
    public static function fromIntras(
        stdClass $row,
        array $contactRows = [],
        array $lookupNames = []
    ): array {
        return [
            'firstname' => trim($row->first_name),
            'lastname' => trim($row->last_name),
            // Facilitator table columns, not custom fields.
            'identification' => Str::trimToNull((string) ($row->identification ?? '')),
            'resume' => Str::trimToNull((string) ($row->cv ?? '')),
            // Only nulls dropped — see the participant mapper for what the default callback eats.
            'custom_fields' => array_filter([
                'identification' => $row->identification ?? null,
                'events_count' => $row->events_count ?? null,
                ...self::profileFields($row, $lookupNames),
            ], fn ($value) => $value !== null),
            'contacts' => self::contactsFromCustomFields($contactRows),
        ];
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
            $value = Str::trimToNull((string) ($contactRows[$name] ?? ''));

            if ($value === null) {
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
