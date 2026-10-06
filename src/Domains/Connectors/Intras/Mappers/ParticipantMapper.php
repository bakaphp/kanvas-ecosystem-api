<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use Baka\Support\Str;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use stdClass;

class ParticipantMapper
{
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
     * `belongsTo` is commented out in the SIPGO model and the Gestor filters it with LIKE. It used
     * to sit in LOOKUP_FIELD_MAP, where the (int) cast turned every value into 0 and the field was
     * silently never set.
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
        $id = (int) $people->get(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value);

        return $id > 0 ? $id : null;
    }

    /**
     * The values SIPGO should hold for this People, given what it holds now. Lookup fields (nivel,
     * área, ...) and the office address are not pushed: they are SIPGO catalog ids, and Kanvas only
     * keeps their names.
     *
     * @param array<string, string> $current custom_fields.name => value, as loadParticipantContacts() returns
     *
     * @return array{participant: array<string, string>, custom_fields: array<string, string>}
     */
    public static function toIntras(People $people, array $current = []): array
    {
        $firstname = Str::trimToNull((string) $people->firstname);
        $lastname = Str::trimToNull((string) $people->lastname);

        $participant = array_filter([
            'first_name' => $firstname,
            'last_name' => $lastname,
            'full_name' => Str::trimToNull($firstname . ' ' . $lastname),
            'position' => Str::trimToNull((string) $people->get('position')),
            'identification' => Str::trimToNull((string) $people->get('identification')),
        ], fn ($value) => $value !== null);

        $customFields = [];

        foreach (self::EXTRA_CUSTOM_FIELD_MAP as $legacyName => $kanvasName) {
            $value = Str::trimToNull((string) $people->get($kanvasName));
            if ($value !== null) {
                $customFields[$legacyName] = $value;
            }
        }

        return [
            'participant' => $participant,
            'custom_fields' => [...$customFields, ...self::contactSlots($people, $current)],
        ];
    }

    /**
     * Which SIPGO slot each Kanvas contact goes in. A value SIPGO already holds keeps its slot and its
     * SIPGO formatting (Kanvas stores phones as their last 10 digits). A new value takes a slot that is
     * empty or holds a value Kanvas no longer has. Nothing is blanked: a contact deleted in Kanvas with
     * nothing to replace it stays in SIPGO.
     *
     * @param array<string, string> $current
     *
     * @return array<string, string>
     */
    private static function contactSlots(People $people, array $current): array
    {
        $contacts = $people->contacts()->orderBy('weight')->orderBy('id')->get();
        $slotsByType = [];

        foreach (self::CONTACT_FIELD_MAP as $slot => $spec) {
            $slotsByType[$spec['type']->value][] = $slot;
        }

        $assigned = [];

        foreach ($slotsByType as $type => $slots) {
            $typeId = ContactType::getByName(ContactTypeEnum::from($type)->getName())->getId();
            $normalize = fn (string $value) => Contact::normalizeValue($value, $typeId);

            $wanted = [];
            foreach ($contacts->where('contacts_types_id', $typeId) as $contact) {
                $wanted[$normalize((string) $contact->value)] ??= trim((string) $contact->value);
            }
            unset($wanted['']);

            $freeSlots = [];
            foreach ($slots as $slot) {
                $held = $normalize((string) ($current[$slot] ?? ''));

                if ($held !== '' && isset($wanted[$held])) {
                    unset($wanted[$held]);

                    continue;
                }

                $freeSlots[] = $slot;
            }

            foreach ($freeSlots as $slot) {
                if ($wanted === []) {
                    break;
                }

                $assigned[$slot] = array_shift($wanted);
            }
        }

        return $assigned;
    }

    /**
     * Only what differs, as from → to. Empty when SIPGO already matches.
     *
     * @param array{participant: array<string, string>, custom_fields: array<string, string>} $desired
     * @param array<string, string> $current
     *
     * @return array{participant?: array<string, array{from: ?string, to: string}>, custom_fields?: array<string, array{from: ?string, to: string}>}
     */
    public static function changes(array $desired, stdClass $row, array $current): array
    {
        $changes = ['participant' => [], 'custom_fields' => []];

        foreach ($desired['participant'] as $column => $value) {
            $from = Str::trimToNull((string) ($row->{$column} ?? ''));
            if ($from !== $value) {
                $changes['participant'][$column] = ['from' => $from, 'to' => $value];
            }
        }

        foreach ($desired['custom_fields'] as $name => $value) {
            $from = Str::trimToNull($current[$name] ?? null);
            if ($from !== $value) {
                $changes['custom_fields'][$name] = ['from' => $from, 'to' => $value];
            }
        }

        return array_filter($changes);
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
