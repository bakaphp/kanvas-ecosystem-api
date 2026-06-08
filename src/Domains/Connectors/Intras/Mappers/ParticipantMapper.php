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
    public const CONTACT_FIELD_MAP = [
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
    public const EXTRA_CUSTOM_FIELD_NAMES = ['ext_1', 'ext_2'];

    /**
     * @param array<string, string> $contactRows custom_fields.name => value, as
     *                                            preloaded from participants_custom_fields
     */
    public static function fromIntras(stdClass $row, array $contactRows = []): array
    {
        return [
            'firstname' => trim($row->first_name),
            'lastname' => trim($row->last_name),
            'custom_fields' => array_filter([
                'position' => $row->position ?? null,
                'identification' => $row->identification ?? null,
                'intras_is_prospect' => (bool) ($row->is_prospect ?? false),
                'intras_classification' => $row->classification ?? null,
                ...self::extraCustomFields($contactRows),
            ]),
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
     *
     * @return array<string, string>
     */
    private static function extraCustomFields(array $contactRows): array
    {
        $extras = [];
        foreach (self::EXTRA_CUSTOM_FIELD_NAMES as $name) {
            $value = trim((string) ($contactRows[$name] ?? ''));
            if ($value !== '') {
                $extras['intras_' . $name] = $value;
            }
        }

        return $extras;
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
            ...self::EXTRA_CUSTOM_FIELD_NAMES,
        ];
    }
}
