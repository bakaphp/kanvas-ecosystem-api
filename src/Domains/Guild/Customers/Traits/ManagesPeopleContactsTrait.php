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
        /** @var Contact|null */
        return match ($contact->contacts_types_id) {
            ContactTypeEnum::PHONE->value => $people->addPhone($contact->value),
            ContactTypeEnum::CELLPHONE->value => $people->addCellphone($contact->value),
            ContactTypeEnum::EMAIL->value => $people->addEmail($contact->value),
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

            if ($createdContact !== null) {
                if ($contact->is_opt_out !== null) {
                    $createdContact->is_opt_out = (int) $contact->is_opt_out;
                    $createdContact->saveOrFail();
                }
            } else {
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

        if (! isset($contact->id) || $contact->id <= 0 || $contact->is_opt_out === null) {
            return false;
        }

        $existingContact = $people->contacts()->where('id', $contact->id)->first();
        if ($existingContact) {
            $existingContact->update([
                'is_opt_out' => (int) ($contact->is_opt_out ?? 0),
                'value' => $contact->value,
            ]);
        }

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

        $contactsToSave = [];
        $keepValues = [];

        foreach ($deduplicatedContacts as $contact) {
            $normalizedValue = Contact::normalizeValue($contact->value, $contact->contacts_types_id);
            $existingContact = $this->findExistingContact($people, $contact, $normalizedValue);

            if ($existingContact) {
                $existingContact->update([
                    'contacts_types_id' => $contact->contacts_types_id,
                    'weight' => $contact->weight,
                    'is_opt_out' => (int) ($contact->is_opt_out ?? 0),
                    'value' => $contact->value,
                ]);
                $keepValues[] = $normalizedValue;
            } else {
                $contactsToSave[] = new Contact([
                    'contacts_types_id' => $contact->contacts_types_id,
                    'value' => $contact->value,
                    'weight' => $contact->weight,
                    'is_opt_out' => (int) ($contact->is_opt_out ?? 0),
                ]);
                $keepValues[] = $normalizedValue;
            }
        }

        if (! empty($keepValues)) {
            $people->contacts()->whereNotIn('value', $keepValues)->delete();
        } else {
            $people->contacts()->delete();
        }

        if (count($contactsToSave) > 0) {
            $people->contacts()->saveMany($contactsToSave);
        }
    }
}
