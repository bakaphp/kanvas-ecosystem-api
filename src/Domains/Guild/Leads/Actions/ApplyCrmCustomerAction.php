<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Support\Collection;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmAddress;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmCustomer;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;

/**
 * Brings what a CRM knows about a customer onto a people, only ever adding or filling in. A null is a
 * value the CRM did not give, so nothing is cleared, and a name is never rewritten: the CRM sends it
 * uppercase when it falls back to its customer directory.
 */
class ApplyCrmCustomerAction
{
    public function __construct(
        protected readonly People $people,
        protected readonly CrmCustomer $customer,
        protected readonly AppInterface $app
    ) {
    }

    /**
     * @return list<array{code: string, field: string}> warnings
     */
    public function execute(): array
    {
        $this->fillMiddlename();

        $contacts = $this->people->contacts()->get();
        $this->addEmails($contacts);
        $this->addPhones($contacts);

        return $this->applyAddress();
    }

    private function fillMiddlename(): void
    {
        $middlename = Str::trimToNull($this->customer->middlename);

        if ($middlename !== null && Str::trimToNull($this->people->middlename) === null) {
            $this->people->middlename = $middlename;
            $this->people->saveOrFail();
        }
    }

    /**
     * @param Collection<int, Contact> $contacts the people's contacts before anything is added
     */
    private function addEmails(Collection $contacts): void
    {
        $known = $this->known($contacts, fn (int $type): bool => Contact::isEmailType($type));

        foreach ($this->customer->normalizedEmails() as $email) {
            if (! in_array($email, $known, true)) {
                $this->people->addEmail($email);
            }
        }
    }

    /**
     * A number already on the people under any phone type is not added again under another.
     */
    /**
     * @param Collection<int, Contact> $contacts the people's contacts before anything is added
     */
    private function addPhones(Collection $contacts): void
    {
        $known = $this->known($contacts, fn (int $type): bool => Contact::isPhoneType($type));
        $phones = $this->customer->phones;

        $slots = [
            ContactTypeEnum::CELLPHONE->getName() => $phones?->cell,
            ContactTypeEnum::PHONE->getName() => $phones?->home,
            ContactTypeEnum::WORK_PHONE->getName() => $phones?->work,
        ];

        foreach ($slots as $typeName => $phone) {
            $digits = Str::sanitizePhoneNumber($phone);

            if ($digits === '') {
                continue;
            }

            $canonical = Contact::normalizeValue($digits, ContactTypeEnum::CELLPHONE->value);

            if (! in_array($canonical, $known, true)) {
                $this->people->addContact($typeName, $digits);
                $known[] = $canonical;
            }
        }
    }

    /**
     * @param Collection<int, Contact> $contacts
     * @param callable(int): bool $ofKind
     *
     * @return list<string> the contacts of that kind, in the form two of them are compared in
     */
    private function known(Collection $contacts, callable $ofKind): array
    {
        return $contacts
            ->filter(fn (Contact $contact): bool => $ofKind((int) $contact->contacts_types_id))
            ->map(fn (Contact $contact): string => Contact::normalizeValue($contact->value, (int) $contact->contacts_types_id))
            ->values()
            ->all();
    }

    /**
     * The people's default address is edited in place, so the row and its id survive a change of street;
     * one is created when there is none. Other addresses are left alone.
     *
     * @return list<array{code: string, field: string}>
     */
    private function applyAddress(): array
    {
        $address = $this->customer->address;

        if (! $address instanceof CrmAddress) {
            return [];
        }

        $street = Str::trimToNull($address->address);

        if ($street === null) {
            return [['code' => 'address_incomplete', 'field' => 'customer.address']];
        }

        $warnings = [];
        $state = Str::trimToNull($address->state);
        $usState = $this->usState($state);

        if ($state !== null && ! $usState instanceof States) {
            $warnings[] = ['code' => 'state_unmapped', 'field' => 'customer.address.state'];
        }

        $values = array_filter(
            [
                'address' => $street,
                'address_2' => Str::trimToNull($address->address_2),
                'city' => Str::trimToNull($address->city),
                'state' => $state,
                'zip' => Str::trimToNull($address->zip),
            ],
            fn (?string $value): bool => $value !== null
        );

        if ($usState instanceof States) {
            $values['state_id'] = (int) $usState->id;
            $values['countries_id'] = (int) $usState->countries_id;
        }

        $current = $this->people->getDefaultAddress();

        if ($current instanceof Address) {
            $current->update($values);
            $this->people->ensureDefaultAddress();

            return $warnings;
        }

        $this->people->address()->save(new Address($values + [
            'city_id' => 0,
            'state_id' => 0,
            'countries_id' => 0,
            'address_type_id' => AddressType::getByName(AddressTypeEnum::HOME->value, $this->app)->getId(),
            'duration' => 0.0,
            'is_default' => 1,
        ]));

        return $warnings;
    }

    /**
     * The CRM sends a US state as its two letters, so it is looked up by code, then by name, among the
     * states of the United States. Other countries reuse the same codes.
     */
    private function usState(?string $state): ?States
    {
        if ($state === null) {
            return null;
        }

        $countryId = Countries::query()->where('code', 'us')->value('id');

        if ($countryId === null) {
            return null;
        }

        return States::query()
            ->where('countries_id', $countryId)
            ->where(
                fn ($query) => $query
                    ->where('code', strtoupper($state))
                    ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($state)])
            )
            ->first();
    }
}
