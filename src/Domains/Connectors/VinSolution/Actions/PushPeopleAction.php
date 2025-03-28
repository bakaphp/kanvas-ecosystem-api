<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Kanvas\Connectors\OCR\DataTransferObjects\DriversLicense;
use Kanvas\Connectors\SalesAssist\Enums\PeopleCustomFieldEnum;
use Kanvas\Connectors\VinSolution\ClientCredential;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Connectors\VinSolution\Services\ContactService;
use Kanvas\Connectors\VinSolution\Support\Address;
use Kanvas\Connectors\VinSolution\Support\Phone;
use Kanvas\Guild\Customers\Models\People;
use Throwable;

class PushPeopleAction
{
    protected ClientCredential $vinCredential;

    public function __construct(
        protected People $people
    ) {
        $this->people = $people;
        $this->vinCredential = ClientCredential::get($this->people->company, $this->people->user, $this->people->app);
    }

    /**
     * Execute the action to push the person to VinSolutions.
     */
    public function execute(): Contact
    {
        return $this->syncContact($this->people);
    }

    /**
     * Add a people as a contact in vin solution.
     */
    protected function syncContact(People $people): Contact
    {
        $contactId = CustomFieldEnum::CONTACT->value;

        $emails = $people->getEmails();
        $phones = $people->getPhones()->merge($people->getCellPhones());
        $exist = $people->get($contactId);

        // Get the full name
        $fullName = $people->getName();
        $nameParts = explode(' ', $fullName);

        $name = [
            'firstName' => $nameParts[0] ?? '',
            'lastName' => end($nameParts) ?? '',
            'middleName' => count($nameParts) > 2 ? implode(' ', array_slice($nameParts, 1, -1)) : '',
        ];

        $contactEmail = [];
        $contactPhone = [];
        $contactAddress = [];

        if ($emails->count() > 0) {
            $i = 1;
            foreach ($emails as $email) {
                $contactEmail[] = [
                    'EmailId' => ! $exist ? 0 : $i,
                    'EmailAddress' => strtolower(trim((string)$email->value)),
                    'EmailType' => 'primary',
                ];
                $i++;
            }
        }

        if ($phones->count() > 0) {
            $i = 1;
            foreach ($phones as $phone) {
                $contactPhone[] = [
                    'PhoneId' => ! $exist ? 0 : $i,
                    'Number' => Phone::removeUSCountryCode($phone->getCleanPhone()),
                    'PhoneType' => 'Cell',
                ];
                $i++;
            }
        }

        if ($people->address()->count() > 0) {
            $i = 1;
            foreach ($people->address as $address) {
                $toAddress = new Address(! $exist ? 0 : $i, $address);
                $contactAddress[] = $toAddress->transform();
                $i++;
            }
        }

        if (! $exist) {
            $contact = [
                'ContactInformation' => [
                    'FirstName' => Str::of($name['firstName'])->trim(),
                    'LastName' => Str::of($name['lastName'])->trim(),
                    'MiddleName' => Str::of($name['middleName'])->trim(),
                    'Emails' => $contactEmail,
                    'Phones' => $contactPhone,
                    'Addresses' => $contactAddress,
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

            $people->set(
                $contactId,
                $contact->id
            );

            // Update again
            if (empty($contact->information)) {
                $this->updateContact(
                    $name,
                    $contactEmail,
                    $contactPhone,
                    $contactAddress,
                    (int) $people->get($contactId),
                    $people
                );
            }
        } else {
            $contact = $this->updateContact(
                $name,
                $contactEmail,
                $contactPhone,
                $contactAddress,
                (int) $people->get($contactId),
                $people
            );
        }

        return $contact;
    }

    /**
     * Detect the format of a given date string.
     */
    protected function detectDateFormat(string $dateString): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return 'Y-m-d';
        } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dateString)) {
            return 'm/d/Y';
        } elseif (preg_match('/^\d{2}-\d{2}-\d{4}$/', $dateString)) {
            return 'm-d-Y';
        } elseif (preg_match('/^\d{2}\/\d{2}\/\d{2}$/', $dateString)) {
            return 'm/d/y'; // short year format
        }
        // Add more patterns as needed

        return null; // Return null if no format matches
    }

    /**
     * Update Contact.
     */
    protected function updateContact(
        array $name,
        array $emails,
        array $phone,
        array $address,
        int $contactId,
        People $people
    ): Contact {
        $vinContactService = new ContactService(
            $this->vinCredential,
        );
        $contact = $vinContactService->getContactByPeople($people);

        $contact->information['FirstName'] = $name['firstName'];
        $contact->information['LastName'] = $name['lastName'];
        $contact->emails = $emails;
        $contact->phones = $phone;

        // If customer has address in vin, don't update it
        $customHasAddressInVin = ! empty($contact->addresses) && ! empty($contact->addresses[0]['StreetAddress']);
        if (! $customHasAddressInVin) {
            $contact->addresses = $address;
        }

        $driversLicenseData = $people->get(PeopleCustomFieldEnum::DRIVERS_LICENSE->value);
        if ($driversLicenseData) {
            $driversLicense = DriversLicense::fromArray($driversLicenseData);

            try {
                $birthDay = Carbon::createFromFormat($this->detectDateFormat($driversLicense->birthDate), $driversLicense->birthDate, 'UTC');
                $expirationData = Carbon::createFromFormat($this->detectDateFormat($driversLicense->expirationDate), $driversLicense->expirationDate, 'UTC');
                $issueDate = Carbon::createFromFormat($this->detectDateFormat($driversLicense->issueDate), $driversLicense->issueDate, 'UTC');
                if (! $customHasAddressInVin) {
                    $contact->licenseData = [
                        'State' => $driversLicense->state,
                        'Name' => $driversLicense->firstName,
                        'LastName' => $driversLicense->lastName,
                        'PostalCode' => $driversLicense->zipCode,
                        'Country' => 'USA',
                        'LicenseID' => $driversLicense->documentNumber,
                        'DateOfBirth' => $birthDay->format('Y-m-d\TH:i:s.u\Z'),
                        'ExpirationDate' => $expirationData->format('Y-m-d\TH:i:s.u\Z'),
                        'IssueDate' => $issueDate->format('Y-m-d\TH:i:s.u\Z'),
                        'Sex' => $driversLicense->sex,
                    ];
                }
            } catch (Throwable $e) {
                // Use Laravel logging
                report($e);
            }
        } elseif ($people->get('get_docs_drivers_license')) {
            $driversLicense = $people->get('get_docs_drivers_license');
            $birthday = $driversLicense['birthday']['year'] . '-' . $driversLicense['birthday']['month'] . '-' . $driversLicense['birthday']['day'];
            $expirationDate = $driversLicense['exp_date']['year'] . '-' . $driversLicense['exp_date']['month'] . '-' . $driversLicense['exp_date']['day'];

            $birthDay = Carbon::parse($birthday, 'UTC');
            $expirationData = Carbon::parse($expirationDate, 'UTC');
            $pattern = '/\b\d{5}(-\d{4})?\b/';

            // Use preg_match to find a ZIP code in the address string
            $zipCode = null;
            if (preg_match($pattern, $driversLicense['address'], $matches)) {
                // If a ZIP code is found, it will be stored in $matches[0]
                $zipCode = $matches[0];
            }

            if (! $customHasAddressInVin) {
                $contact->licenseData = [
                    'State' => $driversLicense['state'],
                    'Name' => $people->firstname,
                    'LastName' => $people->lastname,
                    'PostalCode' => $zipCode,
                    'Country' => 'USA',
                    'LicenseID' => $driversLicense['license'],
                    'DateOfBirth' => $birthDay->format('Y-m-d\TH:i:s.u\Z'),
                    'ExpirationDate' => $expirationData->format('Y-m-d\TH:i:s.u\Z'),
                    'IssueDate' => null,
                    'Sex' => null,
                ];
            }
        }

        $creditAppInfo = $people->get(PeopleCustomFieldEnum::CREDIT_APP->value);
        if ($creditAppInfo && isset($creditAppInfo['personalInformation']) && ! empty($creditAppInfo['personalInformation'])) {
            // Avoid overwriting the credit app info if we have it
            $contact->personalInformation = $creditAppInfo['personalInformation'];
        }

        return $contact->update($this->vinCredential->dealer, $this->vinCredential->user, );
    }
}
