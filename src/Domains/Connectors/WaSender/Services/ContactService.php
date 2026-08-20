<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\WaSender\Client;
use Kanvas\Connectors\WaSender\Traits\FormatsPhoneNumberTrait;
use Kanvas\Exceptions\ValidationException;

class ContactService
{
    use FormatsPhoneNumberTrait;

    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Get all contacts synced with the WhatsApp session.
     */
    public function getAllContacts(): array
    {
        return $this->client->get('/api/contacts');
    }

    /**
     * Get detailed information for a specific contact.
     *
     * @param string $phoneNumber The phone number of the contact in E.164 format
     */
    public function getContactInfo(string $phoneNumber): array
    {
        $formattedPhone = $this->formatPhoneNumber($phoneNumber);

        return $this->client->get("/api/contacts/{$formattedPhone}");
    }

    /**
     * Get the profile picture URL for a specific contact.
     *
     * @param string $phoneNumber The phone number of the contact in E.164 format
     */
    public function getContactProfilePicture(string $phoneNumber): array
    {
        $formattedPhone = $this->formatPhoneNumber($phoneNumber);

        return $this->client->get("/api/contacts/{$formattedPhone}/picture");
    }

    /**
     * Block a specific contact.
     *
     * @param string $phoneNumber The phone number of the contact in E.164 format
     */
    public function blockContact(string $phoneNumber): array
    {
        $formattedPhone = $this->formatPhoneNumber($phoneNumber);

        return $this->client->post("/api/contacts/{$formattedPhone}/block", []);
    }

    /**
     * Unblock a specific contact.
     *
     * @param string $phoneNumber The phone number of the contact in E.164 format
     */
    public function unblockContact(string $phoneNumber): array
    {
        $formattedPhone = $this->formatPhoneNumber($phoneNumber);

        return $this->client->post("/api/contacts/{$formattedPhone}/unblock", []);
    }

    /**
     * Check if a contact exists on WhatsApp.
     *
     * @param string $phoneNumber The phone number to check in E.164 format
     * @return bool True if the contact exists on WhatsApp, false otherwise
     */
    public function checkContactExists(string $phoneNumber): bool
    {
        try {
            $contactInfo = $this->getContactInfo($phoneNumber);

            return $contactInfo['data']['exists'] ?? false;
        } catch (ValidationException $e) {
            // If the contact doesn't exist, the API might return an error
            return false;
        }
    }

    /**
     * Get a list of blocked contacts.
     * Note: This endpoint may not be available in the API, but would be useful
     */
    public function getBlockedContacts(): array
    {
        // This endpoint might not be documented but is common in WhatsApp APIs
        // Adjust the endpoint as needed based on actual API documentation
        return $this->client->get('/api/contacts/blocked');
    }

    /**
     * Search contacts by name or phone number.
     *
     * @param string $query The search query (name or phone number)
     * @return array Array of contacts matching the search query
     */
    public function searchContacts(string $query): array
    {
        // This is a client-side implementation since the API doesn't appear to have a search endpoint
        $allContacts = $this->getAllContacts();
        $matchedContacts = [];

        if (! isset($allContacts['data']) || ! is_array($allContacts['data'])) {
            return ['success' => true, 'data' => []];
        }

        foreach ($allContacts['data'] as $contact) {
            // Search in name, notify, or jid (phone number)
            if (
                (isset($contact['name']) && stripos($contact['name'], $query) !== false) ||
                (isset($contact['notify']) && stripos($contact['notify'], $query) !== false) ||
                (isset($contact['jid']) && stripos($contact['jid'], $query) !== false)
            ) {
                $matchedContacts[] = $contact;
            }
        }

        return ['success' => true, 'data' => $matchedContacts];
    }

    /**
     * The opaque per-account id WhatsApp addresses this number as under lid addressing.
     *
     * Needed because group payloads mention people by `@lid` while we only ever stored a phone
     * number — without this the two never compare equal and a mention is invisible.
     *
     * @param string $phoneNumber digits or a full JID
     */
    public function getLidFromPhoneNumber(string $phoneNumber): ?string
    {
        $response = $this->client->get('/api/lid-from-phone-number/' . rawurlencode($phoneNumber));
        $lid = $response['data']['lid'] ?? $response['lid'] ?? null;

        return is_string($lid) && $lid !== '' ? $lid : null;
    }

    /**
     * Get the current user's own contact info.
     */
    public function getOwnContactInfo(): array
    {
        // This endpoint might not be documented but is common in WhatsApp APIs
        // Adjust the endpoint as needed based on actual API documentation
        return $this->client->get('/api/contacts/me');
    }
}
