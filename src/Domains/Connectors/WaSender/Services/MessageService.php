<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\WaSender\Client;

class MessageService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Send a text message.
     *
     * @param string $to Recipient phone number in E.164 format or Group JID
     * @param string $text The text content of the message
     */
    public function sendTextMessage(string $to, string $text): array
    {
        return $this->client->post('/api/send-message', [
            'to' => $to,
            'text' => $text,
        ]);
    }

    /**
     * Send an image message.
     *
     * @param string $to Recipient phone number in E.164 format or Group JID
     * @param string $imageUrl URL of the image to send
     * @param string|null $caption Optional caption text for the image
     */
    public function sendImageMessage(string $to, string $imageUrl, ?string $caption = null): array
    {
        $data = [
            'to' => $to,
            'imageUrl' => $imageUrl,
        ];

        if ($caption !== null) {
            $data['text'] = $caption;
        }

        return $this->client->post('/api/send-message', $data);
    }

    /**
     * Send a video message.
     *
     * @param string $to Recipient phone number in E.164 format or Group JID
     * @param string $videoUrl URL of the video to send
     * @param string|null $caption Optional caption text for the video
     */
    public function sendVideoMessage(string $to, string $videoUrl, ?string $caption = null): array
    {
        $data = [
            'to' => $to,
            'videoUrl' => $videoUrl,
        ];

        if ($caption !== null) {
            $data['text'] = $caption;
        }

        return $this->client->post('/api/send-message', $data);
    }

    /**
     * Send a document message.
     *
     * @param string $to Recipient phone number in E.164 format or Group JID
     * @param string $documentUrl URL of the document to send
     * @param string|null $caption Optional caption text for the document
     */
    public function sendDocumentMessage(string $to, string $documentUrl, ?string $caption = null): array
    {
        $data = [
            'to' => $to,
            'documentUrl' => $documentUrl,
        ];

        if ($caption !== null) {
            $data['text'] = $caption;
        }

        return $this->client->post('/api/send-message', $data);
    }

    /**
     * Send an audio message.
     *
     * @param string $to Recipient phone number in E.164 format or Group JID
     * @param string $audioUrl URL of the audio file to send
     */
    public function sendAudioMessage(string $to, string $audioUrl): array
    {
        return $this->client->post('/api/send-message', [
            'to' => $to,
            'audioUrl' => $audioUrl,
        ]);
    }

    /**
     * Send a sticker message.
     *
     * @param string $to Recipient phone number in E.164 format or Group JID
     * @param string $stickerUrl URL of the sticker (.webp) to send
     */
    public function sendStickerMessage(string $to, string $stickerUrl): array
    {
        return $this->client->post('/api/send-message', [
            'to' => $to,
            'stickerUrl' => $stickerUrl,
        ]);
    }

    /**
     * Send a contact card.
     *
     * @param string $to Recipient phone number in E.164 format or Group JID
     * @param string $name Contact name
     * @param string $phone Contact phone number
     * @param string|null $message Optional message text to accompany the contact
     */
    public function sendContactCard(string $to, string $name, string $phone, ?string $message = null): array
    {
        $data = [
            'to' => $to,
            'contact' => [
                'name' => $name,
                'phone' => $phone,
            ],
        ];

        if ($message !== null) {
            $data['text'] = $message;
        }

        return $this->client->post('/api/send-message', $data);
    }

    /**
     * Send a location.
     *
     * @param string $to Recipient phone number in E.164 format or Group JID
     * @param float $latitude Latitude of the location
     * @param float $longitude Longitude of the location
     * @param string|null $name Optional name of the location
     * @param string|null $address Optional address of the location
     * @param string|null $message Optional message text to accompany the location
     */
    public function sendLocation(
        string $to,
        float $latitude,
        float $longitude,
        ?string $name = null,
        ?string $address = null,
        ?string $message = null
    ): array {
        $locationData = [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        if ($name !== null) {
            $locationData['name'] = $name;
        }

        if ($address !== null) {
            $locationData['address'] = $address;
        }

        $data = [
            'to' => $to,
            'location' => $locationData,
        ];

        if ($message !== null) {
            $data['text'] = $message;
        }

        return $this->client->post('/api/send-message', $data);
    }

    /**
     * Send a message with multiple content types.
     * This is a flexible method that can be used to send a message with multiple content types,
     * such as text, image, video, etc.
     *
     * @param string $to Recipient phone number in E.164 format or Group JID
     * @param array $messageData Message data containing various content types
     */
    public function sendCustomMessage(string $to, array $messageData): array
    {
        $data = array_merge(['to' => $to], $messageData);

        return $this->client->post('/api/send-message', $data);
    }

    /**
     * Send a message to multiple recipients.
     *
     * @param array $recipients Array of recipient phone numbers in E.164 format
     * @param array $messageData Message data (text, media, etc.)
     * @return array Array of responses for each recipient
     */
    public function sendBulkMessage(array $recipients, array $messageData): array
    {
        $responses = [];

        foreach ($recipients as $recipient) {
            $data = array_merge(['to' => $recipient], $messageData);
            $responses[$recipient] = $this->client->post('/api/send-message', $data);
        }

        return $responses;
    }

    /**
     * Check if a phone number is available on WhatsApp.
     * Note: This method might not be available in the API, but would be useful.
     *
     * @param string $phoneNumber Phone number to check in E.164 format
     */
    public function checkPhoneNumber(string $phoneNumber): array
    {
        // This endpoint might not be documented but is common in WhatsApp APIs
        // Adjust the endpoint as needed based on actual API documentation
        return $this->client->post('/api/check-phone', [
            'phone' => $phoneNumber,
        ]);
    }

    /**
     * Format a phone number to E.164 format.
     *
     * @param string $phoneNumber Phone number to format
     * @param string $defaultCountryCode Default country code if not present in phone number
     * @return string Phone number in E.164 format
     */
    public function formatPhoneNumber(string $phoneNumber, string $defaultCountryCode = '1'): string
    {
        // Remove any non-numeric characters
        $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);

        // If the number doesn't start with +, add the default country code
        if (! str_starts_with($phoneNumber, '+')) {
            // If the number already starts with the country code, don't add it again
            if (! str_starts_with($cleaned, $defaultCountryCode)) {
                $cleaned = $defaultCountryCode . $cleaned;
            }
        }

        return '+' . $cleaned;
    }
}
