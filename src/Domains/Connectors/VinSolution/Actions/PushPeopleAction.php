<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\SalesAssist\Enums\PeopleCustomFieldEnum;
use Kanvas\Connectors\VinSolution\ClientCredential;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Connectors\VinSolution\Services\ContactService;
use Kanvas\Connectors\VinSolution\Support\Address;
use Kanvas\Connectors\VinSolution\Support\Phone;
use Kanvas\Guild\Customers\DataTransferObject\DriverLicense;
use Kanvas\Guild\Customers\Models\Contact as CustomerContact;
use Kanvas\Guild\Customers\Models\People;

class PushPeopleAction
{
    private const string VIN_SOLUTION_DATE_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    protected ClientCredential $vinCredential;

    public function __construct(
        protected People $people
    ) {
        $this->vinCredential = ClientCredential::get(
            $this->people->company,
            $this->people->user,
            $this->people->app
        );
    }

    /**
     * Execute the action to push the person to VinSolutions.
     */
    public function execute(): Contact
    {
        try {
            return $this->syncContact();
        } catch (ClientException $e) {
            if ($this->flagRejectedContactsFromError($e) === 0) {
                throw $e;
            }

            return $this->syncContact();
        }
    }

    private function syncContact(): Contact
    {
        return DB::transaction(function () {
            $this->people->lockForUpdate();

            $contactId = CustomFieldEnum::CONTACT->value;
            $exist = $this->people->get($contactId);

            // Prepare contact data
            $contactEmail = $this->prepareEmails($this->people, ! $exist);
            $contactPhone = $this->preparePhones($this->people, ! $exist);
            $contactAddress = $this->prepareAddresses($this->people, ! $exist);

            // Determine opt-out preferences
            $hasEmailOptOut = $this->people->getEmails()->contains(fn ($email) => $email->is_opt_out === 1);
            $hasPhoneOptOut = $this->people->getPhones()
                ->merge($this->people->getCellPhones())
                ->contains(fn ($phone) => $phone->is_opt_out === 1);

            if (! $exist) {
                // Create new contact
                $contact = [
                    'ContactInformation' => [
                        'FirstName' => Str::of($this->people->firstname)->trim(),
                        'LastName' => Str::of($this->people->lastname)->trim(),
                        'MiddleName' => Str::of($this->people->middlename)->trim(),
                        'Emails' => $contactEmail,
                        'Phones' => $contactPhone,
                        'Addresses' => $contactAddress,
                        'DoNotEmail' => $hasEmailOptOut,
                        'DoNotCall' => $hasPhoneOptOut,
                    ],
                    'LeadInformation' => [
                        'CurrentSalesRepUserId' => $this->vinCredential->user->id ?? 0,
                        'SplitSalesRepUserId' => 0,
                        'LeadSourceId' => 0,
                        'LeadTypeId' => 0,
                        'OnShowRoom' => false,
                        'SaleNotes' => '',
                    ],
                ];

                $contact = Contact::create(
                    $this->vinCredential->dealer,
                    $this->vinCredential->user,
                    $contact
                );

                $this->people->set(
                    $contactId,
                    $contact->id
                );

                // Update again if contact information is empty
                if (empty($contact->information)) {
                    $contact = $this->updateContact(
                        $contactEmail,
                        $contactPhone,
                        $contactAddress,
                        (int) $this->people->get($contactId),
                        $this->people,
                        $hasEmailOptOut,
                        $hasPhoneOptOut
                    );
                }
            } else {
                // Update existing contact
                $contact = $this->updateContact(
                    $contactEmail,
                    $contactPhone,
                    $contactAddress,
                    (int) $this->people->get($contactId),
                    $this->people,
                    $hasEmailOptOut,
                    $hasPhoneOptOut
                );
            }

            return $contact;
        });
    }

    /**
     * Prepare emails for contact. Skips addresses already flagged undeliverable
     * (hard bounce / invalid) so we never re-push one VinSolutions has rejected.
     */
    protected function prepareEmails(People $people, bool $isNew): array
    {
        $emails = $people->getEmails()->filter(
            fn (CustomerContact $email): bool => $email->isDeliverable()
        );
        $contactEmail = [];

        $i = 1;
        foreach ($emails as $email) {
            $contactEmail[] = [
                'EmailId' => $isNew ? 0 : $i,
                'EmailAddress' => strtolower(trim((string) $email->value)),
                'EmailType' => 'primary',
            ];
            $i++;
        }

        return $contactEmail;
    }

    /**
     * VinSolutions returns a 400 (System.ArgumentException) when it can't verify an address or phone
     * — stricter than what we can validate locally. Parse the rejected value out of the response,
     * mark the matching contact INVALID (prepare{Emails,Phones} then stop sending it), and report how
     * many we flagged so the caller knows whether a retry is worth it. Returns 0 for any other error
     * so genuine failures still surface to Sentry.
     */
    protected function flagRejectedContactsFromError(ClientException $e): int
    {
        $response = $e->getResponse();
        if ($response === null) {
            return 0;
        }

        $body = (string) $response->getBody();

        return $this->flagRejectedEmails($body) + $this->flagRejectedPhones($body);
    }

    /** "<email> is not valid" — flag the matching email contact. */
    private function flagRejectedEmails(string $body): int
    {
        if (! str_contains($body, 'is not valid')) {
            return 0;
        }

        preg_match_all('/[\w.+-]+@[\w-]+\.[\w.-]+/', $body, $matches);
        $rejected = array_map('strtolower', $matches[0] ?? []);
        if ($rejected === []) {
            return 0;
        }

        $flagged = 0;
        foreach ($this->people->getEmails() as $email) {
            $value = strtolower(trim((string) $email->value));
            if (in_array($value, $rejected, true) && ! $email->validation_status->isPermanentFailure()) {
                $email->markInvalid();
                $flagged++;
            }
        }

        return $flagged;
    }

    /**
     * "Not a valid Phone Number: <digits>" — match on the exact digits we submitted (country code
     * stripped, same transform as preparePhones) and flag that phone contact INVALID.
     */
    private function flagRejectedPhones(string $body): int
    {
        preg_match_all('/Not a valid Phone Number:\s*(\d+)/', $body, $matches);
        $rejected = $matches[1] ?? [];
        if ($rejected === []) {
            return 0;
        }

        $flagged = 0;
        foreach ($this->people->getPhones()->merge($this->people->getCellPhones()) as $phone) {
            $submitted = Phone::removeUSCountryCode($phone->getCleanPhone());
            if (in_array($submitted, $rejected, true) && ! $phone->validation_status->isPermanentFailure()) {
                $phone->markInvalid();
                $flagged++;
            }
        }

        return $flagged;
    }

    /**
     * Prepare phones for contact. Skips numbers already flagged undeliverable (hard bounce /
     * invalid) so we never re-push one VinSolutions has rejected.
     */
    protected function preparePhones(People $people, bool $isNew): array
    {
        $contactPhone = [];

        $i = 1;
        foreach ($people->getPhones()->merge($people->getCellPhones()) as $phone) {
            if (! $phone->isDeliverable()) {
                continue;
            }
            $contactPhone[] = [
                'PhoneId' => $isNew ? 0 : $i,
                'Number' => Phone::removeUSCountryCode($phone->getCleanPhone()),
                'PhoneType' => 'Cell',
            ];
            $i++;
        }

        return $contactPhone;
    }

    /**
     * Prepare addresses for contact.
     */
    protected function prepareAddresses(People $people, bool $isNew): array
    {
        $contactAddress = [];

        $addresses = $people->address()->latest('created_at')->get();

        if ($addresses->count() > 0) {
            $i = 1;
            foreach ($addresses as $address) {
                $toAddress = new Address($isNew ? 0 : $i, $address);
                $contactAddress[] = $toAddress->transform();
                $i++;
            }
        }

        return $contactAddress;
    }

    protected function processDriversLicense(People $people): ?array
    {
        $license = $people->getDriverLicense();

        if ($license === null) {
            return null;
        }

        return [
            'State' => $license->state,
            'Name' => $license->firstname ?? $people->firstname,
            'LastName' => $license->lastname ?? $people->lastname,
            'PostalCode' => $this->resolveLicenseZipCode($people, $license),
            'Country' => 'USA',
            'LicenseID' => $license->number,
            'DateOfBirth' => $license->dob?->format(self::VIN_SOLUTION_DATE_FORMAT),
            'ExpirationDate' => $license->expirationDate?->format(self::VIN_SOLUTION_DATE_FORMAT),
            'IssueDate' => null,
            'Sex' => null,
        ];
    }

    protected function resolveLicenseZipCode(People $people, DriverLicense $license): ?string
    {
        if ($license->address !== null && preg_match('/\b\d{5}(-\d{4})?\b/', $license->address, $matches)) {
            return $matches[0];
        }

        return $people->address()->where('is_default', true)->first()?->zip;
    }

    /**
     * Update Contact.
     */
    protected function updateContact(
        array $emails,
        array $phone,
        array $address,
        int $contactId,
        People $people,
        bool $hasEmailOptOut = false,
        bool $hasPhoneOptOut = false
    ): Contact {
        $vinContactService = new ContactService(
            $this->vinCredential,
        );
        $contact = $vinContactService->getContactByPeople($people);

        // Update basic information
        $contact->information['FirstName'] = $this->people->firstname;
        $contact->information['LastName'] = $this->people->lastname;
        $contact->emails = $emails;
        $contact->phones = $phone;

        // Set opt-out preferences from people contacts
        $contact->information['DoNotEmail'] = $hasEmailOptOut;
        $contact->information['DoNotCall'] = $hasPhoneOptOut;

        // Check if customer already has address in Vin
        $customHasAddressInVin = ! empty($contact->addresses) && ! empty($contact->addresses[0]['StreetAddress']);
        if (! $customHasAddressInVin) {
            $contact->addresses = $address;

            // Process driver's license only if we're updating the address
            $licenseData = $this->processDriversLicense($people);
            if ($licenseData) {
                $contact->licenseData = $licenseData;
            }
        }

        // Process credit app info
        $creditAppInfo = $people->get(PeopleCustomFieldEnum::CREDIT_APP->value);
        if ($creditAppInfo && isset($creditAppInfo['personalInformation']) && ! empty($creditAppInfo['personalInformation'])) {
            // Avoid overwriting the credit app info if we have it
            $contact->personalInformation = $creditAppInfo['personalInformation'];
        }

        return $contact->update($this->vinCredential->dealer, $this->vinCredential->user);
    }
}
