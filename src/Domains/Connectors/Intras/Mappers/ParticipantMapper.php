<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use Baka\Support\Str;
use Kanvas\Connectors\Intras\Enums\SipgoEntityEnum;
use Kanvas\Connectors\Intras\Mappers\Concerns\MapsContactSlots;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationFieldEnum;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationSourceEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use stdClass;

class ParticipantMapper
{
    use MapsContactSlots;

    /** The People type the pull assigns; only this type is proposed as a new SIPGO participant. */
    public const string PARTICIPANT_PEOPLE_TYPE = 'Participant';

    /** `participants_statuses.name` a new participant gets when Kanvas holds no `estatus`. */
    public const string DEFAULT_STATUS = 'ACTIVO';

    /**
     * `custom_fields.name` is not unique in SIPGO — `email_oficina` exists under modules 2, 3 and 4 —
     * and every participants_custom_fields row points at module 4. A write that resolved the field by
     * name alone could file the value under another module's field, where the Gestor never shows it.
     */
    public const int PARTICIPANT_CUSTOM_FIELDS_MODULE_ID = 4;

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
        'sexo' => 'sexo',
        // Distinct from pa_code: the Gestor has two fields labelled "Código". The grid's is
        // participants.id; this one is The Conference's own code, in participants_custom_fields.
        'tccode' => 'tc_code',
        'tcporcent' => 'tc_porcentaje',
        'certificado_primer_nombre' => 'certificado_primer_nombre',
        'certificado_segundo_nombre' => 'certificado_segundo_nombre',
        'certificado_primer_apellido' => 'certificado_primer_apellido',
        'certificado_segundo_apellido' => 'certificado_segundo_apellido',
        // SIPGO stores the birthday month/day as their own custom fields rather than deriving them
        // from dob, and the Gestor filters on the stored values — which can disagree with dob. We
        // import what is stored instead of recomputing.
        'dobmoth' => 'dob_mes',
        'dobdate' => 'dob_dia',
    ];

    /**
     * Kept out of the pull maps on purpose: Kanvas owns these, and importing them back would let a
     * stale SIPGO value overwrite a newer classification.
     */
    public const array CLASSIFICATION_FIELDS = [
        PeopleClassificationFieldEnum::LEVEL->value => 'Nivel Jerárquico',
        PeopleClassificationFieldEnum::AREA->value => 'Área Funcional',
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
        'participants_statuses_id' => ['table' => 'participants_statuses', 'custom_field' => 'estatus'],
        'professions_id' => ['table' => 'professions', 'custom_field' => 'profesion'],
        'gifts_id' => ['table' => 'gifts', 'custom_field' => 'tipo_regalo'],
    ];

    /**
     * `participants` column → People custom field, stored verbatim.
     *
     * `departments_id` is named like an FK but the legacy column is VARCHAR(254) free text — the
     * Gestor filters it with LIKE, so it must bypass catalog-id resolution.
     */
    public const array DIRECT_FIELD_MAP = [
        'departments_id' => 'department',
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
            // A real People column, so it is returned alongside the name rather than as a custom
            // field. dob_mes / dob_dia are separate stored values — see EXTRA_CUSTOM_FIELD_MAP.
            'dob' => Str::trimToNull((string) ($row->dob ?? '')),
            // array_filter's default callback drops false and 0 as well as null, which silently
            // erased `intras_is_prospect => false` — the CLIENTE half of the Gestor's Relación
            // Comercial filter. Only nulls may be dropped here.
            'custom_fields' => array_filter([
                // participants.id IS the PA — SIPGO has no separate code column and the grid's
                // "Código" is literally the id. Stored unprefixed; the PA##### rendering lives
                // outside both legacy repos, so the display format is the UI's business.
                'pa_code' => isset($row->id) ? (string) $row->id : null,
                'position' => $row->position ?? null,
                'identification' => $row->identification ?? null,
                'intras_is_prospect' => (bool) ($row->is_prospect ?? false),
                'intras_classification' => $row->classification ?? null,
                'potencialidad' => $row->potentiality ?? null,
                // Imported verbatim and never recomputed: the evaluations engine behind it is not
                // being ported, so this scalar is the only satisfaction figure that will exist.
                'satisfaccion' => $row->events_satisfaction ?? null,
                'descuento' => $row->conference_discount ?? null,
                // Booleans, so they depend on the array_filter callback below keeping false.
                'contacto_clave_axis' => isset($row->is_axis_participant)
                    ? (bool) $row->is_axis_participant
                    : null,
                'representante_general' => isset($row->general_representative)
                    ? (bool) $row->general_representative
                    : null,
                ...self::profileFields($row, $contactRows, $lookupNames),
            ], fn ($value) => $value !== null),
            'contacts' => self::contactsFromCustomFields($contactRows),
        ];
    }

    public static function participantId(People $people): ?int
    {
        return SipgoEntityEnum::PARTICIPANT->legacyIdOf($people);
    }

    /**
     * The values SIPGO should hold for this People, given what it holds now. Catalog columns (nivel,
     * área, ...) come out as names under `catalog`; SipgoChangeSet resolves them to ids. The office
     * address is not pushed: it is a `companies_offices` row shared by everyone in the company.
     *
     * @param array<string, string> $current custom_fields.name => value, as loadParticipantContacts() returns
     *
     * @return array{row: array<string, string>, custom_fields: array<string, string>, catalog: array<string, array{table: string, name: string}>}
     */
    public static function toIntras(People $people, array $current = []): array
    {
        $firstname = Str::trimToNull((string) $people->firstname);
        $lastname = Str::trimToNull((string) $people->lastname);

        $row = array_filter([
            'first_name' => $firstname,
            'last_name' => $lastname,
            'full_name' => Str::trimToNull($firstname . ' ' . $lastname),
            'position' => Str::trimToNull((string) $people->get('position')),
            'identification' => Str::trimToNull((string) $people->get('identification')),
            ...self::customFieldValues($people, array_flip(self::DIRECT_FIELD_MAP)),
        ], fn ($value) => $value !== null);

        $catalog = [];

        foreach (self::LOOKUP_FIELD_MAP as $column => $spec) {
            $name = Str::trimToNull((string) $people->get($spec['custom_field']));
            if ($name !== null) {
                $catalog[$column] = ['table' => $spec['table'], 'name' => $name];
            }
        }

        return [
            'row' => $row,
            'custom_fields' => [
                ...self::customFieldValues($people, array_flip(self::EXTRA_CUSTOM_FIELD_MAP)),
                ...self::contactSlots($people, $current, self::CONTACT_FIELD_MAP),
            ],
            'catalog' => $catalog,
        ];
    }

    /**
     * Everything a new `participants` row needs that the update mapping does not carry: the SIPGO
     * company (required for the Gestor to show the person) and a status, which is NOT NULL.
     *
     * @return array{row: array<string, string>, custom_fields: array<string, string>, catalog: array<string, array{table: string, name: string}>}
     */
    public static function toIntrasForCreate(People $people, int $companyId): array
    {
        $desired = self::toIntras($people);
        $desired['row']['companies_id'] = (string) $companyId;
        $desired['catalog']['participants_statuses_id'] ??= ['table' => 'participants_statuses', 'name' => self::DEFAULT_STATUS];

        return $desired;
    }

    public static function isParticipant(People $people): bool
    {
        return $people->peopleType?->name === self::PARTICIPANT_PEOPLE_TYPE;
    }

    /**
     * @param array<string, string> $map Kanvas custom field => SIPGO name
     *
     * @return array<string, string>
     */
    private static function customFieldValues(People $people, array $map): array
    {
        $values = [];

        foreach ($map as $kanvasName => $legacyName) {
            $value = Str::trimToNull((string) $people->get($kanvasName));
            if ($value !== null) {
                $values[$legacyName] = $value;
            }
        }

        return $values;
    }

    /**
     * The applied classification as PushToIntrasAction items. Empty when the person is waiting
     * on review or is not a SIPGO participant.
     *
     * @return list<array{kind: string, legacy_id: int, changes: array{custom_fields: array<string, array{from: null, to: string}>}}>
     */
    public static function classificationChanges(People $people): array
    {
        $participantId = self::participantId($people);

        if (
            $participantId === null
            || $people->get(PeopleClassificationFieldEnum::SOURCE->value) === PeopleClassificationSourceEnum::PENDING_REVIEW->value
        ) {
            return [];
        }

        $changes = [];

        foreach (array_keys(self::CLASSIFICATION_FIELDS) as $name) {
            $value = Str::trimToNull((string) $people->get($name));

            if ($value !== null) {
                $changes[$name] = ['from' => null, 'to' => $value];
            }
        }

        return $changes === [] ? [] : [SipgoEntityEnum::PARTICIPANT->item($participantId, ['custom_fields' => $changes])];
    }

    /**
     * `companies_offices` FK → [legacy catalog, output key].
     *
     * The only address in the whole of SIPGO: `participants` has no address columns, so the Gestor
     * reaches dirección / país / ciudad / sector through the participant's office.
     */
    public const array OFFICE_LOOKUP_FIELD_MAP = [
        'countries_id' => ['table' => 'countries', 'key' => 'country'],
        'cities_id' => ['table' => 'cities', 'key' => 'city'],
        'districts_id' => ['table' => 'districts', 'key' => 'sector'],
    ];

    /**
     * @return list<string>
     */
    public static function officeLookupTables(): array
    {
        return array_values(array_unique(array_column(self::OFFICE_LOOKUP_FIELD_MAP, 'table')));
    }

    /**
     * Flatten a `companies_offices` row into the shape the importer writes to People.
     *
     * `sector` (legacy `districts`) is returned alongside the address rather than inside it:
     * `peoples_address` has no slot for it, and forcing it into `state` would misfile a Dominican
     * sector as a province. The importer stores it as a custom field.
     *
     * @param array<string, array<int, string>> $lookupNames catalog => [id => name]
     *
     * @return array{address: ?string, city: ?string, country: ?string, sector: ?string}|null
     */
    public static function addressFromOffice(?stdClass $office, array $lookupNames = []): ?array
    {
        if ($office === null) {
            return null;
        }

        $resolved = [];

        foreach (self::OFFICE_LOOKUP_FIELD_MAP as $column => $spec) {
            $id = $office->{$column} ?? null;
            $resolved[$spec['key']] = $id === null
                ? null
                : Str::trimToNull((string) ($lookupNames[$spec['table']][(int) $id] ?? ''));
        }

        $address = [
            'address' => Str::trimToNull((string) ($office->address ?? '')),
            'city' => $resolved['city'] ?? null,
            'country' => $resolved['country'] ?? null,
            'sector' => $resolved['sector'] ?? null,
        ];

        return array_filter($address, fn ($value) => $value !== null) === []
            ? null
            : $address;
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

        foreach (self::DIRECT_FIELD_MAP as $column => $kanvasName) {
            $value = Str::trimToNull((string) ($row->{$column} ?? ''));
            if ($value !== null) {
                $fields[$kanvasName] = $value;
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
