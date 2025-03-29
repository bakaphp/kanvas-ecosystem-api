<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use Baka\Helpers\DateHelper;
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
        $contactId = CustomFieldEnum::CONTACT->value;
        $exist = $this->people->get($contactId);

        // Prepare contact data
        $contactEmail = $this->prepareEmails($this->people, ! $exist);
        $contactPhone = $this->preparePhones($this->people, ! $exist);
        $contactAddress = $this->prepareAddresses($this->people, ! $exist);

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
                    $this->people
                );
            }
        } else {
            // Update existing contact
            $contact = $this->updateContact(
                $contactEmail,
                $contactPhone,
                $contactAddress,
                (int) $this->people->get($contactId),
                $this->people
            );
        }

        return $contact;
    }

    /**
     * Prepare emails for contact.
     */
    protected function prepareEmails(People $people, bool $isNew): array
    {
        $emails = $people->getEmails();
        $contactEmail = [];

        if ($emails->count() > 0) {
            $i = 1;
            foreach ($emails as $email) {
                $contactEmail[] = [
                    'EmailId' => $isNew ? 0 : $i,
                    'EmailAddress' => strtolower(trim((string)$email->value)),
                    'EmailType' => 'primary',
                ];
                $i++;
            }
        }

        return $contactEmail;
    }

    /**
     * Prepare phones for contact.
     */
    protected function preparePhones(People $people, bool $isNew): array
    {
        $phones = $people->getPhones()->merge($people->getCellPhones());
        $contactPhone = [];

        if ($phones->count() > 0) {
            $i = 1;
            foreach ($phones as $phone) {
                $contactPhone[] = [
                    'PhoneId' => $isNew ? 0 : $i,
                    'Number' => Phone::removeUSCountryCode($phone->getCleanPhone()),
                    'PhoneType' => 'Cell',
                ];
                $i++;
            }
        }

        return $contactPhone;
    }

    /**
     * Prepare addresses for contact.
     */
    protected function prepareAddresses(People $people, bool $isNew): array
    {
        $contactAddress = [];

        if ($people->address()->count() > 0) {
            $i = 1;
            foreach ($people->address as $address) {
                $toAddress = new Address($isNew ? 0 : $i, $address);
                $contactAddress[] = $toAddress->transform();
                $i++;
            }
        }

        return $contactAddress;
    }

    /**
     * Process drivers license data.
     */
    protected function processDriversLicense(People $people): ?array
    {
        $driversLicenseData = $people->get(PeopleCustomFieldEnum::DRIVERS_LICENSE->value);

        if ($driversLicenseData) {
            try {
                $driversLicense = DriversLicense::fromArray($driversLicenseData);
                $birthDay = Carbon::createFromFormat(DateHelper::detectDateFormat($driversLicense->birthDate), $driversLicense->birthDate, 'UTC');
                $expirationData = Carbon::createFromFormat(DateHelper::detectDateFormat($driversLicense->expirationDate), $driversLicense->expirationDate, 'UTC');
                $issueDate = Carbon::createFromFormat(DateHelper::detectDateFormat($driversLicense->issueDate), $driversLicense->issueDate, 'UTC');

                return [
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
            } catch (Throwable $e) {
                report($e);

                return null;
            }
        } elseif ($legacyLicense = $people->get('get_docs_drivers_license')) {
            try {
                $birthday = $legacyLicense['birthday']['year'] . '-' . $legacyLicense['birthday']['month'] . '-' . $legacyLicense['birthday']['day'];
                $expirationDate = $legacyLicense['exp_date']['year'] . '-' . $legacyLicense['exp_date']['month'] . '-' . $legacyLicense['exp_date']['day'];

                $birthDay = Carbon::parse($birthday, 'UTC');
                $expirationData = Carbon::parse($expirationDate, 'UTC');

                // Extract zip code
                $zipCode = null;
                $pattern = '/\b\d{5}(-\d{4})?\b/';
                if (preg_match($pattern, $legacyLicense['address'], $matches)) {
                    $zipCode = $matches[0];
                }

                return [
                    'State' => $legacyLicense['state'],
                    'Name' => $people->firstname,
                    'LastName' => $people->lastname,
                    'PostalCode' => $zipCode,
                    'Country' => 'USA',
                    'LicenseID' => $legacyLicense['license'],
                    'DateOfBirth' => $birthDay->format('Y-m-d\TH:i:s.u\Z'),
                    'ExpirationDate' => $expirationData->format('Y-m-d\TH:i:s.u\Z'),
                    'IssueDate' => null,
                    'Sex' => null,
                ];
            } catch (Throwable $e) {
                report($e);

                return null;
            }
        }

        return null;
    }

    /**
     * Update Contact.
     */
    protected function updateContact(
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

        // Update basic information
        $contact->information['FirstName'] = $this->people->firstname;
        $contact->information['LastName'] = $this->people->lastname;
        $contact->emails = $emails;
        $contact->phones = $phone;

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
