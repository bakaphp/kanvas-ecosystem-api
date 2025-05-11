<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\WaSender\Client;
use Kanvas\Exceptions\ValidationException;

class SessionService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Get all WhatsApp sessions.
     */
    public function getAllSessions(): array
    {
        return $this->client->get('/whatsapp-sessions');
    }

    /**
     * Create a new WhatsApp session.
     *
     * @param string $name Name of the WhatsApp session
     * @param string $phoneNumber Phone number in international format
     * @param bool $accountProtection Enable account protection features
     * @param bool $logMessages Enable message logging
     * @param string|null $webhookUrl URL for receiving webhook notifications
     * @param bool $webhookEnabled Enable webhook notifications
     * @param array|null $webhookEvents Array of events to receive webhook notifications for
     */
    public function createSession(
        string $name,
        string $phoneNumber,
        bool $accountProtection = true,
        bool $logMessages = true,
        ?string $webhookUrl = null,
        bool $webhookEnabled = false,
        ?array $webhookEvents = null
    ): array {
        $data = [
            'name' => $name,
            'phone_number' => $phoneNumber,
            'account_protection' => $accountProtection,
            'log_messages' => $logMessages,
        ];

        if ($webhookUrl !== null) {
            $data['webhook_url'] = $webhookUrl;
            $data['webhook_enabled'] = $webhookEnabled;

            if ($webhookEvents !== null) {
                $data['webhook_events'] = $webhookEvents;
            }
        }

        return $this->client->post('/whatsapp-sessions', $data);
    }

    /**
     * Get a specific WhatsApp session.
     */
    public function getSession(int $sessionId): array
    {
        return $this->client->get("/whatsapp-sessions/{$sessionId}");
    }

    /**
     * Update a WhatsApp session.
     *
     * @param int $sessionId ID of the WhatsApp session
     * @param array $data Session data to update
     */
    public function updateSession(int $sessionId, array $data): array
    {
        return $this->client->put("/whatsapp-sessions/{$sessionId}", $data);
    }

    /**
     * Delete a WhatsApp session.
     */
    public function deleteSession(int $sessionId): array
    {
        return $this->client->delete("/whatsapp-sessions/{$sessionId}");
    }

    /**
     * Connect a WhatsApp session and generate QR code.
     *
     * @param int $sessionId ID of the WhatsApp session
     * @param bool $qrAsImage Return QR code as image
     */
    public function connectSession(int $sessionId, bool $qrAsImage = false): array
    {
        $data = [];
        if ($qrAsImage) {
            $data['qr_as_image'] = true;
        }

        return $this->client->post("/whatsapp-sessions/{$sessionId}/connect", $data);
    }

    /**
     * Get QR code for a WhatsApp session.
     */
    public function getSessionQrCode(int $sessionId): array
    {
        return $this->client->get("/whatsapp-sessions/{$sessionId}/qrcode");
    }

    /**
     * Disconnect a WhatsApp session.
     */
    public function disconnectSession(int $sessionId): array
    {
        return $this->client->post("/whatsapp-sessions/{$sessionId}/disconnect", []);
    }

    /**
     * Regenerate API key for a WhatsApp session.
     */
    public function regenerateApiKey(int $sessionId): array
    {
        return $this->client->post("/whatsapp-sessions/{$sessionId}/regenerate-key", []);
    }

    /**
     * Get status of the current WhatsApp session.
     */
    public function getSessionStatus(): array
    {
        return $this->client->get('/api/status');
    }

    /**
     * Get all WhatsApp groups.
     */
    public function getGroups(): array
    {
        return $this->client->get('/api/groups');
    }

    /**
     * Create a WhatsApp session and connect it in one step.
     *
     * This is a convenience method that combines creating a session and connecting it.
     *
     * @param string $name Name of the WhatsApp session
     * @param string $phoneNumber Phone number in international format
     * @param bool $accountProtection Enable account protection features
     * @param bool $logMessages Enable message logging
     * @param string|null $webhookUrl URL for receiving webhook notifications
     * @param bool $webhookEnabled Enable webhook notifications
     * @param array|null $webhookEvents Array of events to receive webhook notifications for
     */
    public function createAndConnectSession(
        string $name,
        string $phoneNumber,
        bool $accountProtection = true,
        bool $logMessages = true,
        ?string $webhookUrl = null,
        bool $webhookEnabled = false,
        ?array $webhookEvents = null
    ): array {
        // Step 1: Create the session
        $sessionData = $this->createSession(
            $name,
            $phoneNumber,
            $accountProtection,
            $logMessages,
            $webhookUrl,
            $webhookEnabled,
            $webhookEvents
        );

        // Get the session ID from the response
        if (! isset($sessionData['id'])) {
            throw new ValidationException('Failed to create WhatsApp session: Session ID not found in response');
        }

        $sessionId = $sessionData['id'];

        // Step 2: Connect the session
        $connectionData = $this->connectSession($sessionId, true);

        return [
            'session' => $sessionData,
            'connection' => $connectionData,
        ];
    }

    /**
     * Check if a session is currently connected.
     *
     * @param int|null $sessionId The session ID to check. If null, checks the current session.
     * @return bool True if the session is connected, false otherwise.
     */
    public function isSessionConnected(?int $sessionId = null): bool
    {
        if ($sessionId !== null) {
            $status = $this->getSession($sessionId);
        } else {
            $status = $this->getSessionStatus();
        }

        return isset($status['status']) && $status['status'] === 'connected';
    }

    /**
     * Wait for a session to connect by repeatedly checking its status.
     *
     * @param int $sessionId The session ID to check
     * @param int $maxAttempts Maximum number of attempts to check status
     * @param int $delaySeconds Seconds to wait between attempts
     * @return bool True if the session connected successfully, false if it timed out
     */
    public function waitForSessionConnection(int $sessionId, int $maxAttempts = 30, int $delaySeconds = 2): bool
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $session = $this->getSession($sessionId);

            if (isset($session['status']) && $session['status'] === 'connected') {
                return true;
            }

            // If the status is "need_scan", we can provide feedback to the caller
            if (isset($session['status']) && $session['status'] === 'need_scan') {
                // You might implement a callback here to notify the caller
                // that the QR code needs to be scanned
            }

            // Wait before checking again
            sleep($delaySeconds);
        }

        return false;
    }
}
