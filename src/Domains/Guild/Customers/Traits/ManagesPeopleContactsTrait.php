<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Traits;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

        $query = $people->contacts()
            ->where('contacts_types_id', $contact->contacts_types_id);

        if (Contact::isPhoneType($contact->contacts_types_id)) {
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

        try {
            // Serialize contact syncs per person. The body runs read -> keep/add -> delete-whereNotIn
            // non-atomically, so two overlapping syncs (overlapping pulls, or a CONTACT_SAVED workflow
            // re-entering mid-sync) were shredding each other's rows and minting a new id every time.
            Cache::lock('people-contacts-sync:' . $people->getKey(), 10)->block(
                5,
                fn () => $this->runContactsSyncForUpdate($people, $contacts)
            );
        } catch (LockTimeoutException) {
            // Another sync for this person is already applying the same contacts — skip the race.
        }
    }

    private function runContactsSyncForUpdate(People $people, DataCollection $contacts): void
    {
        $deduplicatedContacts = $this->deduplicateContacts($contacts);

        // @todo remove once frontend sends opt-out updates via a separate mutation
        if ($this->isOptOutOnlyUpdate($deduplicatedContacts, $people)) {
            return;
        }

        // @todo temporary diagnostic — remove once the VinSolution sync recreation is confirmed fixed.
        $existingBefore = $people->contacts()->get(['id', 'value', 'contacts_types_id'])->toArray();
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
                $decisions[] = ['in' => $contact->value, 'type' => $contact->contacts_types_id, 'norm' => $normalizedValue, 'result' => 'matched', 'id' => $existingContact->id];
            } else {
                $createdContact = $this->addNewContact($people, $contact);

                if ($createdContact !== null) {
                    $keepIds[] = $createdContact->id;
                    $decisions[] = ['in' => $contact->value, 'type' => $contact->contacts_types_id, 'norm' => $normalizedValue, 'result' => 'added(updateOrCreate)', 'id' => $createdContact->id];
                } else {
                    $contactsToAdd[] = new Contact([
                        'contacts_types_id' => $contact->contacts_types_id,
                        'value' => $contact->value,
                        'is_opt_out' => (int) ($contact->is_opt_out ?? 0),
                        'weight' => $contact->weight,
                    ]);
                    $decisions[] = ['in' => $contact->value, 'type' => $contact->contacts_types_id, 'norm' => $normalizedValue, 'result' => 'added(insert)', 'id' => null];
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

        // Only fires when a sync actually deletes a contact — i.e. only when the bug reproduces.
        if (! empty($deletedIds)) {
            Log::warning('contact_sync_recreated', [
                'peoples_id' => $people->getKey(),
                'existing_before' => $existingBefore,
                'incoming_decisions' => $decisions,
                'keep_ids' => $keepIds,
                'deleted_ids' => $deletedIds,
            ]);
        }

        if (! empty($keepIds)) {
            $people->contacts()->whereNotIn('id', $keepIds)->delete();
        } else {
            $people->contacts()->delete();
        }
    }
}
