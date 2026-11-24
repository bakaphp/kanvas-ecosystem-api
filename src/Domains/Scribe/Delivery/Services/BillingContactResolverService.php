<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Services;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;

/**
 * Who receives a customer document. In order: the person a caller names (alone), the quote's own
 * contact (the SOW signer), every person linked to the customer organization carrying the
 * `billing-contact` tag, then the linked person whose email is the billing email frozen on the
 * document. No guess past that: the agent asks.
 */
class BillingContactResolverService
{
    public const string TAG = 'billing-contact';

    /**
     * @return non-empty-list<People>
     */
    public function resolve(Invoice|Quote $document, ?People $named = null): array
    {
        $customer = $document->customer;

        if ($named !== null) {
            $this->assertSameCompany($document, $named);
            $this->markBillingContact($named, $customer);

            return [$named];
        }

        $quoteContact = $document instanceof Quote ? $document->contact : null;
        if ($quoteContact !== null) {
            return [$quoteContact];
        }

        $tagged = $this->taggedContacts($document, $customer);
        if ($tagged !== []) {
            return $tagged;
        }

        $byEmail = $this->personWithBillingEmail($document, $customer);
        if ($byEmail !== null) {
            return [$byEmail];
        }

        $name = $document->billable_display_name ?? $customer?->name ?? 'this customer';

        throw new DocumentDeliveryException(
            DocumentDeliveryException::NO_CONTACT,
            "I don't know who receives documents at {$name}. Ask the user for the billing contact's name and "
            . 'email or phone, create them with create_person, link them to the customer with '
            . 'link_person_to_organization, tag them ' . self::TAG . ' with tag_person, then try again.'
        );
    }

    /**
     * A person someone named as the recipient is the billing contact from now on: tagged, and linked to
     * the customer so the next document finds them without being told.
     */
    public function markBillingContact(People $person, ?Organization $customer): void
    {
        $person->addTag(
            self::TAG,
            $person->app,
            $person->user ?? $customer?->user ?? $person->company->user,
            $person->company
        );
        $customer?->addPeople($person);
    }

    public function isBillingContact(People $person): bool
    {
        return $person->hasTag([self::TAG]);
    }

    /**
     * @return list<People>
     */
    private function taggedContacts(Invoice|Quote $document, ?Organization $customer): array
    {
        if ($customer === null) {
            return [];
        }

        // Tags live on the social connection, so the tag check runs per person instead of a whereHas.
        return $customer->peoples()
            ->get()
            ->filter(fn (People $person): bool => (int) $person->companies_id === (int) $document->companies_id
                && ! $person->is_deleted
                && $this->isBillingContact($person))
            ->sortBy(fn (People $person): int => $person->getId())
            ->values()
            ->all();
    }

    private function personWithBillingEmail(Invoice|Quote $document, ?Organization $customer): ?People
    {
        $email = Str::trimToNull((string) $document->billable_email);
        if ($customer === null || $email === null) {
            return null;
        }

        foreach ($customer->peoples()->get() as $person) {
            /** @var People $person */
            foreach ($person->getEmails() as $contact) {
                if (strcasecmp(trim((string) $contact->value), $email) === 0) {
                    return $person;
                }
            }
        }

        return null;
    }

    private function assertSameCompany(Invoice|Quote $document, People $person): void
    {
        if ((int) $person->companies_id !== (int) $document->companies_id) {
            throw new DocumentDeliveryException(
                DocumentDeliveryException::NO_CONTACT,
                'That person does not belong to this company.'
            );
        }
    }
}
