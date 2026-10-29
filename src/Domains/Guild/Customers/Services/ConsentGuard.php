<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Services;

use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * The two questions every outbound path has to ask before it speaks. One implementation, so a new
 * way of sending cannot ship with its own, slightly different, idea of who may be contacted.
 */
final class ConsentGuard
{
    /**
     * The people-level flag is not redundant with the lead one. ApplyDoNotContactAction flags every
     * lead that exists at the time, but a connector sync can create a NEW lead for the same person
     * afterwards, and that lead carries no flag of its own.
     */
    public static function isDoNotContact(?People $people, ?Lead $lead = null): bool
    {
        return (bool) $lead?->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value)
            || (bool) $people?->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value);
    }

    /**
     * For a sender with no lead in hand. Opt-outs from before the people-level flag existed were
     * written on the lead only, so every lead of the person still counts.
     */
    public static function isPersonDoNotContact(People $people): bool
    {
        return self::isDoNotContact($people)
            || $people->leads()->get()->contains(
                fn (Lead $lead): bool => (bool) $lead->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value)
            );
    }

    /**
     * Per-address opt-out, for the ones a stop request never produced: a DMS sync pushing
     * `doNotEmail`, or a hard bounce.
     */
    public static function isDestinationOptedOut(?People $people, string $destination, bool $isEmail): bool
    {
        if ($people === null) {
            return false;
        }

        // Normalize the destination once, against the type we are actually sending to — not against
        // each row's own type. Phone normalization strips a value to its last 10 digits, so an email
        // address compared under it collapses to whatever digits it contains and can match a real
        // number. Filtering the rows by channel also stops us comparing an address to a phone at all.
        $normalizedDestination = Contact::normalizeValue(
            $destination,
            $isEmail ? ContactTypeEnum::EMAIL->value : ContactTypeEnum::CELLPHONE->value,
        );

        return $people->contacts()
            ->where('is_opt_out', 1)
            ->whereIn('contacts_types_id', $isEmail ? Contact::EMAIL_TYPES : Contact::PHONE_TYPES)
            ->get()
            ->contains(fn (Contact $contact): bool => Contact::normalizeValue(
                (string) $contact->value,
                (int) $contact->contacts_types_id,
            ) === $normalizedDestination);
    }
}
