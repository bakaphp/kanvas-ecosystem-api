<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Leads;

use Illuminate\Support\Str;
use Kanvas\Connectors\VinSolution\Client;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Dealers\User;

class Contact
{
    public int $id;
    public array $information = [];
    public array $emails = [];
    public array $phones = [];
    public array $customerConsent = [];
    public array $dealerTeam = [];
    public array $smsPreferences = [];
    public array $leadInformation = [];
    public array $personalInformation = [];
    public array $licenseData = [];
    public array $addresses = [];

    /**
     * Initialize.
     */
    public function __construct(array $data)
    {
        $this->id = $data['ContactId'];
        $this->information = $data['ContactInformation'] ?? [];
        $this->customerConsent = $data['CustomerConsent'] ?? [];
        $this->emails = $this->information['Emails'] ?? [];
        $this->phones = $this->information['Phones'] ?? [];
        $this->smsPreferences = $data['SmsPreferences'] ?? [];
        $this->dealerTeam = $data['DealerTeam'] ?? [];
        $this->leadInformation = $data['leadInformation'] ?? [];
        $this->personalInformation = $data['PersonalInformation'] ?? [];
        $this->addresses = $data['Addresses'] ?? [];
        $this->licenseData = $data['LicenseData'] ?? [];
    }

    /**
     * Get all the leads for the given dealer.
     */
    public static function getAll(Dealer $dealer, User $user, string $search, array $params = []): array
    {
        $client = new Client($dealer->id, $user->id);
        $client->useDigitalShowRoomKey();

        $data = [];
        $data['DealerId'] = $dealer->id;
        $data['UserId'] = $user->id;

        $params = http_build_query($params);

        $response = $client->get(
            '/gateway/v1/contact?dealerId='.$dealer->id.'&userId='.$user->id.'&searchText='.$search.'&'.$params,
        );

        return $response;
    }

    /**
     * Get a contact by its ID.
     */
    public static function getById(Dealer $dealer, User $user, int $contactId): Contact
    {
        $client = new Client($dealer->id, $user->id);
        $data['DealerId'] = $dealer->id;
        $data['UserId'] = $user->id;

        $response = $client->get('/gateway/v1/contact/'.$contactId.'?dealerId='.$dealer->id.'&userId='.$user->id);

        return new Contact($response[0]);
    }

    /**
     * Create a new contact.
     */
    public static function create(Dealer $dealer, User $user, array $data): self
    {
        $client = new Client($dealer->id, $user->id);
        $data['DealerId'] = $dealer->id;
        $data['UserId'] = $user->id;

        if (isset($data['ContactInformation']['Phones'])) {
            $data['ContactInformation']['Phones'][0]['Number'] = Str::limit(
                preg_replace('/[^0-9]/', '', $data['ContactInformation']['Phones'][0]['Number']),
                10,
                ''
            );
        }

        if (isset($data['ContactInformation']['Addresses'])
            && empty($data['ContactInformation']['Addresses'])) {
            unset($data['ContactInformation']['Addresses']);
        }

        if (!isset($data['ContactInformation']['DealerId'])) {
            $data['ContactInformation']['DealerId'] = $data['DealerId'];
        }

        $response = $client->post('/gateway/v1/contact', json_encode($data));

        return new self($response);
    }

    /**
     * Create a new contact.
     */
    public function update(Dealer $dealer, User $user): self
    {
        $client = new Client($dealer->id, $user->id);

        $data = [];
        $data['DealerId'] = $dealer->id;
        $data['UserId'] = $user->id;

        // Initialize a new array for cleaned information
        $cleanedInformation = $this->information;

        //clean information of emails
        if (!empty($this->emails)) {
            $cleanedEmails = [];
            foreach ($this->emails as $key => $value) {
                if (isset($this->information['Emails'][$key]['EmailAddress']) &&
                    $this->information['Emails'][$key]['EmailAddress'] !== $this->emails[$key]['EmailAddress']) {
                    $cleanedEmails[$key] = $this->emails[$key];
                }
            }

            if (!empty($cleanedEmails)) {
                $cleanedInformation['Emails'] = $cleanedEmails;
            } else {
                // Remove the emails key completely instead of unsetting
                $cleanedInformation = array_diff_key($cleanedInformation, ['Emails' => []]);
            }
        }

        //clean information of phone
        if (!empty($this->phones)) {
            $cleanedPhones = [];
            foreach ($this->phones as $key => $value) {
                if (isset($this->information['Phones'][$key]['Number']) &&
                    $this->information['Phones'][$key]['Number'] !== $this->phones[$key]['Number']) {
                    $cleanedPhones[$key] = $this->phones[$key];
                }
            }

            if (!empty($cleanedPhones)) {
                $cleanedInformation['Phones'] = $cleanedPhones;
            } else {
                // Remove the phones key completely instead of unsetting
                $cleanedInformation = array_diff_key($cleanedInformation, ['Phones' => []]);
            }
        }

        $data['ContactInformation'] = $cleanedInformation;

        if (!empty($this->leadInformation)) {
            $data['LeadInformation'] = $this->leadInformation;
        }

        if (!empty($this->licenseData)) {
            $data['LicenseData'] = $this->licenseData;
        }

        if (!empty($this->personalInformation)) {
            $data['PersonalInformation'] = $this->personalInformation;
        }

        if (!empty($this->addresses)) {
            $cleanedAddresses = [];
            foreach ($this->addresses as $key => $address) {
                if (!($address['State'] !== null && empty(trim($address['State'])))) {
                    $cleanedAddresses[$key] = $address;
                }
            }

            if (!empty($cleanedAddresses)) {
                $data['ContactInformation']['Addresses'] = $cleanedAddresses;
            }
        }

        if (!empty($this->phones) && isset($data['ContactInformation']['Phones'])) {
            if (isset($data['ContactInformation']['Phones'][0])) {
                $data['ContactInformation']['Phones'][0]['Number'] = Str::limit(
                    preg_replace('/[^0-9]/', '', $data['ContactInformation']['Phones'][0]['Number']),
                    10,
                    ''
                );
            }
        }

        $response = $client->put('/gateway/v1/contact/'.$this->id, json_encode($data));

        $data['ContactId'] = $this->id;

        return new self($data);
    }
}
