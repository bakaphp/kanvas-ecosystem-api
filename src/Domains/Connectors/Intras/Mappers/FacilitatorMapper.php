<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use Baka\Support\Str;
use Kanvas\Connectors\Intras\Mappers\Concerns\MapsContactSlots;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use stdClass;

class FacilitatorMapper
{
    use MapsContactSlots;

    /**
     * `facilitators_custom_fields` name → Kanvas contact type.
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
     * People custom fields the participant pull also writes (`estatus` from participants_statuses,
     * `pais` from the company office). On a person who is both, the participant owns them: pushing
     * them as facilitator values would propose the other catalog's name, or the employer's country
     * as a home country.
     */
    public const array SHARED_WITH_PARTICIPANT = ['estatus', 'pais'];

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

    /**
     * The values SIPGO should hold for this facilitator. Identification and CV live on the Event
     * domain's Facilitator row, not on the People; `events_count` is SIPGO's own and never pushed.
     *
     * @param array<string, string> $current facilitators_custom_fields.name => value
     *
     * @return array{row: array<string, string>, custom_fields: array<string, string>, catalog: array<string, array{table: string, name: string}>}
     */
    public static function toIntras(
        People $people,
        ?Facilitator $facilitator,
        array $current = [],
        bool $alsoParticipant = false
    ): array {
        $row = array_filter([
            'first_name' => Str::trimToNull((string) $people->firstname),
            'last_name' => Str::trimToNull((string) $people->lastname),
            'identification' => Str::trimToNull((string) $facilitator?->identification),
            'cv' => Str::trimToNull((string) $facilitator?->resume),
        ], fn ($value) => $value !== null);

        $catalog = [];

        foreach (self::LOOKUP_FIELD_MAP as $column => $spec) {
            if ($alsoParticipant && in_array($spec['custom_field'], self::SHARED_WITH_PARTICIPANT, true)) {
                continue;
            }

            $name = Str::trimToNull((string) $people->get($spec['custom_field']));
            if ($name !== null) {
                $catalog[$column] = ['table' => $spec['table'], 'name' => $name];
            }
        }

        return [
            'row' => $row,
            'custom_fields' => self::contactSlots($people, $current, self::CONTACT_FIELD_MAP),
            'catalog' => $catalog,
        ];
    }
}
