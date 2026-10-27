<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions\Concerns;

use Baka\Support\Str;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Zoho\Services\ZohoFieldMapService;
use Kanvas\Guild\Customers\Concerns\ManagesPeopleContacts;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Address as AddressModel;
use Kanvas\Guild\Customers\Models\People;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * The person half of a Zoho record, lead or deal: name, email, phone, date of birth and address,
 * each read through the company's field map with ZohoFieldMapService::PERSON_FIELDS as the default.
 */
trait SyncsZohoPerson
{
    use ManagesPeopleContacts;

    protected function personValue(array $record, array $fieldMapping, string $key): ?string
    {
        $value = $record[ZohoFieldMapService::personFieldName($fieldMapping, $key)] ?? null;

        return is_scalar($value) ? Str::trimToNull((string) $value) : null;
    }

    /**
     * A deal has no First_Name of its own; its contact arrives as `Contact_Name: {name, id}`.
     */
    protected function personFullName(array $record): ?string
    {
        $contact = $record['Contact_Name'] ?? null;
        $name = $record['Full_Name'] ?? (is_array($contact) ? ($contact['name'] ?? null) : $contact);

        return is_scalar($name) ? Str::trimToNull((string) $name) : null;
    }

    protected function personFirstName(array $record, array $fieldMapping): ?string
    {
        $firstName = $this->personValue($record, $fieldMapping, 'first_name');

        if ($firstName !== null) {
            return $firstName;
        }

        $fullName = $this->personFullName($record);

        return $fullName !== null ? Str::before($fullName, ' ') : null;
    }

    /**
     * A person row needs a first name; the email handle stands in when Zoho sends neither name.
     */
    protected function personFirstNameForCreate(array $record, array $fieldMapping): ?string
    {
        $email = $this->personValue($record, $fieldMapping, 'email');

        return $this->personFirstName($record, $fieldMapping)
            ?? ($email !== null ? Str::before($email, '@') : null);
    }

    protected function personLastName(array $record, array $fieldMapping): ?string
    {
        $lastName = $this->personValue($record, $fieldMapping, 'last_name');

        if ($lastName !== null) {
            return $lastName;
        }

        $fullName = $this->personFullName($record);

        return $fullName !== null && str_contains($fullName, ' ')
            ? Str::trimToNull(Str::after($fullName, ' '))
            : null;
    }

    protected function personContacts(array $record, array $fieldMapping): DataCollection
    {
        $contacts = [];
        $email = $this->personValue($record, $fieldMapping, 'email');
        $phone = $this->personValue($record, $fieldMapping, 'phone');

        if ($email !== null) {
            $contacts[] = [
                'value' => $email,
                'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                'weight' => 0,
            ];
        }

        if ($phone !== null) {
            $contacts[] = [
                'value' => $phone,
                'contacts_types_id' => ContactTypeEnum::PHONE->value,
                'weight' => 0,
            ];
        }

        return Contact::collect($contacts, DataCollection::class);
    }

    /**
     * Street is the gate: CreatePeopleAction drops an address without one, so city or zip alone
     * would be written on an update and silently skipped on a create.
     */
    protected function personAddress(array $record, array $fieldMapping): ?Address
    {
        $street = $this->personValue($record, $fieldMapping, 'street');

        if ($street === null) {
            return null;
        }

        return Address::fromArray([
            'address' => $street,
            'city' => $this->personValue($record, $fieldMapping, 'city'),
            'state' => $this->personValue($record, $fieldMapping, 'state'),
            'zip' => $this->personValue($record, $fieldMapping, 'zip'),
            'is_default' => true,
        ]);
    }

    protected function personDateOfBirth(array $record, array $fieldMapping): ?Carbon
    {
        $dob = $this->personValue($record, $fieldMapping, 'Date_of_Birth');

        if ($dob === null) {
            return null;
        }

        try {
            return Carbon::parse($dob)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Zoho is the source of truth for what it carries; a field it does not carry is left alone
     * rather than wiped, and contacts are merged rather than replaced.
     */
    protected function syncPerson(People $people, array $record, array $fieldMapping): void
    {
        $firstName = $this->personFirstName($record, $fieldMapping);
        $lastName = $this->personLastName($record, $fieldMapping);
        $dob = $this->personDateOfBirth($record, $fieldMapping);

        if ($firstName !== null) {
            $people->firstname = $firstName;
        }

        if ($lastName !== null) {
            $people->lastname = $lastName;
        }

        if ($dob !== null) {
            $people->dob = $dob->format('Y-m-d');
        }

        $people->disableWorkflows();
        $people->saveOrFail();

        $this->syncContactsForUpdate($people, $this->personContacts($record, $fieldMapping), merge: true);

        $address = $this->personAddress($record, $fieldMapping);

        if ($address !== null) {
            $people->replaceDefaultAddress($address);
        }
    }

    protected function syncAddressCustomFields(Model $entity, ?AddressModel $address): void
    {
        foreach (ZohoFieldMapService::addressValues($address) as $key => $value) {
            $entity->set($key === 'street' ? 'address' : $key, $value);
        }
    }
}
