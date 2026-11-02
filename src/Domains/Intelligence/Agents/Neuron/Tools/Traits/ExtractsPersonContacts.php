<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Guild\Customers\Models\People;

/**
 * Pulls a person's first email / phone from the already-loaded `contacts` relation (an email is any
 * value containing "@"), so callers don't re-query per person. Requires `contacts` to be eager-loaded.
 */
trait ExtractsPersonContacts
{
    protected function primaryEmail(People $person): ?string
    {
        return $person->contacts->first(fn (object $c): bool => str_contains((string) $c->value, '@'))?->value;
    }

    protected function primaryPhone(People $person): ?string
    {
        return $person->contacts->first(fn (object $c): bool => ! str_contains((string) $c->value, '@'))?->value;
    }

    /**
     * @return list<string>
     */
    protected function allEmails(People $person): array
    {
        return $this->contactValues($person, isEmail: true);
    }

    /**
     * @return list<string>
     */
    protected function allPhones(People $person): array
    {
        return $this->contactValues($person, isEmail: false);
    }

    /**
     * @return list<string>
     */
    private function contactValues(People $person, bool $isEmail): array
    {
        return $person->contacts
            ->filter(fn (object $c): bool => str_contains((string) $c->value, '@') === $isEmail)
            ->pluck('value')
            ->map(fn ($value): string => (string) $value)
            ->unique()
            ->values()
            ->all();
    }
}
