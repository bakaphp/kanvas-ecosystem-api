<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Leads;

use Illuminate\Support\Str;
use Kanvas\Connectors\VinSolution\Client;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Dealers\User;
use Kanvas\Connectors\VinSolution\Exceptions\ContactNotFoundException;

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
            '/gateway/v1/contact?dealerId=' . $dealer->id . '&userId=' . $user->id . '&searchText=' . $search . '&' . $params,
        );

        return $response;
    }

    /**
     * Get a contact by its ID.
     */
    public static function getById(Dealer $dealer, User $user, int $contactId): Contact
    {
        $client = new Client($dealer->id, $user->id);

        $response = $client->get('/gateway/v1/contact/' . $contactId . '?dealerId=' . $dealer->id . '&userId=' . $user->id);

        return self::fromApiResponse($response, $contactId);
    }

    /**
     * VinSolutions answers with a list, but hands back an empty one — not a 404 — for a contact
     * the dealer can no longer reach (merged, moved to another dealer, or deleted), and
     * occasionally the bare contact object instead of a list.
     */
    public static function fromApiResponse(array $response, int $contactId): self
    {
        $contact = $response[0] ?? $response;

        if (! is_array($contact) || ! isset($contact['ContactId'])) {
            throw new ContactNotFoundException(
                'VinSolutions contact ' . $contactId . ' not found for this dealer'
            );
        }

        return new self($contact);
    }

    /**
     * Create a new contact.
     */
    public static function create(Dealer $dealer, User $user, array $data): self
    {
        $client = new Client($dealer->id, $user->id);
        $data['DealerId'] = $dealer->id;
        $data['UserId'] = $user->id;

        if (isset($data['ContactInformation']['Phones'][0])) {
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

        if (! isset($data['ContactInformation']['DealerId'])) {
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

        $data = $this->buildUpdatePayload($dealer->id, $user->id);

        $client->put('/gateway/v1/contact/' . $this->id, json_encode($data));

        $data['ContactId'] = $this->id;

        return new self($data);
    }

    public function buildUpdatePayload(int $dealerId, int $userId): array
    {
        $data = [];
        $data['DealerId'] = $dealerId;
        $data['UserId'] = $userId;

        $cleanedInformation = $this->information;

        // Only the entries that actually changed travel; anything else VinSolutions already has.
        // When nothing changed we drop the key instead of echoing back what VinSolutions gave us —
        // its own stored values may include an address/number its verifier now rejects, and sending
        // those back 400s the PUT on every retry forever.
        $cleanedEmails = $this->changedEntries(
            $this->emails,
            $this->information['Emails'] ?? [],
            'EmailAddress'
        );
        if (! empty($cleanedEmails)) {
            $cleanedInformation['Emails'] = $cleanedEmails;
        } else {
            unset($cleanedInformation['Emails']);
        }

        $cleanedPhones = $this->changedEntries(
            $this->phones,
            $this->information['Phones'] ?? [],
            'Number'
        );
        if (! empty($cleanedPhones)) {
            $cleanedInformation['Phones'] = $cleanedPhones;
        } else {
            unset($cleanedInformation['Phones']);
        }

        $data['ContactInformation'] = $cleanedInformation;

        if (! empty($this->leadInformation)) {
            $data['LeadInformation'] = $this->leadInformation;
        }

        if (! empty($this->licenseData)) {
            $data['LicenseData'] = $this->licenseData;
        }

        if (! empty($this->personalInformation)) {
            $data['PersonalInformation'] = $this->personalInformation;
        }

        if (! empty($this->addresses)) {
            $cleanedAddresses = [];
            foreach ($this->addresses as $key => $address) {
                if (! ($address['State'] !== null && empty(trim($address['State'])))) {
                    $cleanedAddresses[$key] = $address;
                }
            }

            if (! empty($cleanedAddresses)) {
                $data['ContactInformation']['Addresses'] = $cleanedAddresses;
            }
        }

        if (! empty($this->phones) && isset($data['ContactInformation']['Phones'])) {
            if (isset($data['ContactInformation']['Phones'][0])) {
                $data['ContactInformation']['Phones'][0]['Number'] = Str::limit(
                    preg_replace('/[^0-9]/', '', $data['ContactInformation']['Phones'][0]['Number']),
                    10,
                    ''
                );
            }
        }

        return $data;
    }

    private function changedEntries(array $submitted, array $current, string $field): array
    {
        $changed = [];

        foreach ($submitted as $key => $entry) {
            if (isset($entry[$field], $current[$key][$field])
                && $current[$key][$field] !== $entry[$field]) {
                $changed[$key] = $entry;
            }
        }

        return $changed;
    }
}
