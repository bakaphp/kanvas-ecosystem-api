<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Traits;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
        if (isset($contact->id) && (int) $contact->id > 0) {
            /** @var Contact|null $byId */
            $byId = $people->contacts()
                ->where('id', $contact->id)
                ->first();

            if ($byId !== null) {
                return $byId;
            }

            // The incoming id is stale or belongs to a third-party system (after a prior sync
            // recreated the row, its local id no longer matches what the provider sends back).
            // Never treat that as "new" — fall through to natural-key matching so we update the
            // existing row in place instead of deleting + recreating it on every sync.
        }

        $phoneTypes = [
            ContactTypeEnum::PHONE->value,
            ContactTypeEnum::CELLPHONE->value,
            ContactTypeEnum::WORK_PHONE->value,
        ];

        $query = $people->contacts()
            ->where('contacts_types_id', $contact->contacts_types_id);

        if (in_array($contact->contacts_types_id, $phoneTypes, true)) {
            // Match on the canonical NANP form (last 10 digits) so a country-code prefix or
            // punctuation difference (+1 / (201) / -) doesn't read as a removed-then-added phone.
            $query->whereRaw("RIGHT(REGEXP_REPLACE(value, '[^0-9]', ''), 10) = ?", [$normalizedValue]);
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
                ->whereRaw("RIGHT(REGEXP_REPLACE(value, '[^0-9]', ''), 10) = ?", [$normalizedValue])
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

        $debugSync = (bool) $people->company?->get('contact_sync_debug');
        $existingBefore = $debugSync
            ? $people->contacts()->get(['id', 'value', 'contacts_types_id'])->toArray()
            : [];
        $decisions = [];

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
                $decisions[] = ['incoming' => $contact->value, 'type' => $contact->contacts_types_id, 'normalized' => $normalizedValue, 'result' => 'matched', 'id' => $existingContact->id];
            } else {
                $createdContact = $this->addNewContact($people, $contact);

                if ($createdContact !== null) {
                    $keepIds[] = $createdContact->id;
                    $decisions[] = ['incoming' => $contact->value, 'type' => $contact->contacts_types_id, 'normalized' => $normalizedValue, 'result' => 'added(updateOrCreate)', 'id' => $createdContact->id];
                } else {
                    $contactsToAdd[] = new Contact([
                        'contacts_types_id' => $contact->contacts_types_id,
                        'value' => $contact->value,
                        'is_opt_out' => (int) ($contact->is_opt_out ?? 0),
                        'weight' => $contact->weight,
                    ]);
                    $decisions[] = ['incoming' => $contact->value, 'type' => $contact->contacts_types_id, 'normalized' => $normalizedValue, 'result' => 'added(insert)', 'id' => null];
                }
            }
        }

        if (! empty($contactsToAdd)) {
            $savedContacts = $people->contacts()->saveMany($contactsToAdd);
            foreach ($savedContacts as $saved) {
                $keepIds[] = $saved->id;
            }
        }

        $deletedIds = $people->contacts()
            ->when(! empty($keepIds), fn ($q) => $q->whereNotIn('id', $keepIds))
            ->pluck('id')
            ->all();

        if (! empty($keepIds)) {
            $people->contacts()->whereNotIn('id', $keepIds)->delete();
        } else {
            $people->contacts()->delete();
        }

        if ($debugSync) {
            // Flip the company `contact_sync_debug` flag on to capture two consecutive pulls:
            // if `existing_before` already holds the incoming (value,type) but they show as
            // "added", the matcher is the problem; if `existing_before` differs from incoming,
            // the third-party payload is the problem.
            Log::channel('single')->info('contact_sync_debug', [
                'peoples_id' => $people->getKey(),
                'existing_before' => $existingBefore,
                'decisions' => $decisions,
                'keep_ids' => $keepIds,
                'deleted_ids' => $deletedIds,
            ]);
        }
    }
}
