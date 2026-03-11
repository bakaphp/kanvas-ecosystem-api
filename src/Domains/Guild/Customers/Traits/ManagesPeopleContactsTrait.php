<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Traits;

use Illuminate\Support\Collection;
use Kanvas\Guild\Customers\DataTransferObject\Contact as ContactData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Spatie\LaravelData\DataCollection;

trait ManagesPeopleContactsTrait
{
    protected function deduplicateContacts(DataCollection $contacts): Collection
    {
        return $contacts
            ->toCollection()
            ->filter(fn ($contact) => ! empty($contact->value))
            ->unique(fn ($contact) => Contact::normalizeValue($contact->value, $contact->contacts_types_id) . '_' . $contact->contacts_types_id)
            ->values();
    }

    protected function findExistingContact(People $people, ContactData $contact, string $normalizedValue): ?Contact
    {
        if (isset($contact->id) && $contact->id > 0) {
            /** @var Contact|null */
            return $people->contacts()
                ->where('id', $contact->id)
                ->first();
        }

        $phoneTypes = [
            ContactTypeEnum::PHONE->value,
            ContactTypeEnum::CELLPHONE->value,
            ContactTypeEnum::WORK_PHONE->value,
        ];

        $query = $people->contacts()
            ->where('contacts_types_id', $contact->contacts_types_id);

        if (in_array($contact->contacts_types_id, $phoneTypes, true)) {
            $query->whereRaw("REGEXP_REPLACE(value, '[^0-9]', '') = ?", [$normalizedValue]);
        } else {
            $query->where('value', $normalizedValue);
        }

        /** @var Contact|null */
        return $query->first();
    }

    protected function addNewContact(People $people, ContactData $contact): ?Contact
    {
        $isOptOut = (int) ($contact->is_opt_out ?? 0);

        /** @var Contact|null */
        return match ($contact->contacts_types_id) {
            ContactTypeEnum::PHONE->value => $people->addPhone($contact->value, $isOptOut, $contact->weight),
            ContactTypeEnum::CELLPHONE->value => $people->addCellphone($contact->value, $isOptOut, $contact->weight),
            ContactTypeEnum::EMAIL->value => $people->addEmail($contact->value, $isOptOut, $contact->weight),
            default => null,
        };
    }

    protected function syncContactsForCreate(People $people, DataCollection $contacts): void
    {
        if (! $contacts->count()) {
            return;
        }

        $existingContacts = $people->contacts()
            ->select('value', 'contacts_types_id')
            ->get()
            ->map(fn ($c) => Contact::normalizeValue($c->value, $c->contacts_types_id) . '_' . $c->contacts_types_id)
            ->toArray();

        $deduplicatedContacts = $this->deduplicateContacts($contacts);
        $contactsToAdd = [];

        foreach ($deduplicatedContacts as $contact) {
            $contactKey = Contact::normalizeValue($contact->value, $contact->contacts_types_id) . '_' . $contact->contacts_types_id;

            if (in_array($contactKey, $existingContacts)) {
                continue;
            }

            $createdContact = $this->addNewContact($people, $contact);

            if ($createdContact === null) {
                $contactsToAdd[] = new Contact([
                    'contacts_types_id' => $contact->contacts_types_id,
                    'value' => $contact->value,
                    'is_opt_out' => (int) ($contact->is_opt_out ?? 0),
                    'weight' => $contact->weight,
                ]);
            }
        }

        if (! empty($contactsToAdd)) {
            $people->contacts()->saveMany($contactsToAdd);
        }
    }

    /**
     * @todo remove once frontend sends opt-out updates via a separate mutation
     */
    protected function isOptOutOnlyUpdate(Collection $contacts, People $people): bool
    {
        if ($contacts->count() !== 1) {
            return false;
        }

        $contact = $contacts->first();
        if ($contact->is_opt_out === null) {
            return false;
        }

        $normalizedValue = Contact::normalizeValue($contact->value, $contact->contacts_types_id);
        $existingContact = $this->findExistingContact($people, $contact, $normalizedValue);

        //use a normal query without call existing cause of the id issue we cant us it because if overwritten
        if (! $existingContact) {
            $existingContact = $people->contacts()
                ->where('contacts_types_id', $contact->contacts_types_id)
                ->whereRaw("REGEXP_REPLACE(value, '[^0-9]', '') = ?", [$normalizedValue])
                ->first();
        }

        if (! $existingContact) {
            return true;
        }

        $existingContact->is_opt_out = (int) ($contact->is_opt_out ?? 0);
        $existingContact->value = $contact->value;
        $existingContact->saveOrFail();

        return true;
    }

    protected function syncContactsForUpdate(People $people, DataCollection $contacts): void
    {
        if (! $contacts->count()) {
            return;
        }

        $deduplicatedContacts = $this->deduplicateContacts($contacts);

        // @todo remove once frontend sends opt-out updates via a separate mutation
        if ($this->isOptOutOnlyUpdate($deduplicatedContacts, $people)) {
            return;
        }

        $keepIds = [];
        $contactsToAdd = [];

        foreach ($deduplicatedContacts as $contact) {
            $normalizedValue = Contact::normalizeValue($contact->value, $contact->contacts_types_id);
            $existingContact = $this->findExistingContact($people, $contact, $normalizedValue);

            if ($existingContact) {
                $existingContact->value = $contact->value;
                $existingContact->contacts_types_id = $contact->contacts_types_id;
                $existingContact->weight = $contact->weight;
                $existingContact->is_opt_out = (int) ($contact->is_opt_out ?? $existingContact->is_opt_out);
                $existingContact->saveOrFail();
                $keepIds[] = $existingContact->id;
            } else {
                $createdContact = $this->addNewContact($people, $contact);

                if ($createdContact !== null) {
                    $keepIds[] = $createdContact->id;
                } else {
                    $contactsToAdd[] = new Contact([
                        'contacts_types_id' => $contact->contacts_types_id,
                        'value' => $contact->value,
                        'is_opt_out' => (int) ($contact->is_opt_out ?? 0),
                        'weight' => $contact->weight,
                    ]);
                }
            }
        }

        if (! empty($contactsToAdd)) {
            $savedContacts = $people->contacts()->saveMany($contactsToAdd);
            foreach ($savedContacts as $saved) {
                $keepIds[] = $saved->id;
            }
        }

        if (! empty($keepIds)) {
            $people->contacts()->whereNotIn('id', $keepIds)->delete();
        } else {
            $people->contacts()->delete();
        }
    }
}
