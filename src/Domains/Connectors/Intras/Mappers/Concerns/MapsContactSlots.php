<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers\Concerns;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;

trait MapsContactSlots
{
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
     * Which SIPGO slot each Kanvas contact goes in. A value SIPGO already holds keeps its slot and its
     * SIPGO formatting (Kanvas stores phones as their last 10 digits). A new value takes a slot that is
     * empty or holds a value Kanvas no longer has. Nothing is blanked: a contact deleted in Kanvas with
     * nothing to replace it stays in SIPGO.
     *
     * @param array<string, string> $current SIPGO custom field name => value
     * @param array<string, array{type: ContactTypeEnum, weight: int}> $fieldMap SIPGO slot => contact type
     *
     * @return array<string, string>
     */
    protected static function contactSlots(People $people, array $current, array $fieldMap): array
    {
        $contacts = $people->contacts()->orderBy('weight')->orderBy('id')->get();
        $slotsByType = [];

        foreach ($fieldMap as $slot => $spec) {
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
}
