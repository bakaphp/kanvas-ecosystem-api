<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\WaSender\Client;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * Sessions are managed with the app's account token, not a session's own key: that key only sends.
 */
class SessionService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $accountToken = $app->get(ConfigurationEnum::PERSONAL_ACCESS_TOKEN->value);

        $this->client = new Client(
            $app,
            $company,
            apiKey: is_scalar($accountToken) ? (string) $accountToken : null
        );
    }

    /**
     * WaSender names the QR differently across plans.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function extractQr(array $payload): ?string
    {
        return $payload['qr_code'] ?? $payload['qr'] ?? $payload['qrCode'] ?? $payload['qrcode'] ?? null;
    }

    /**
     * A new session that already posts to our webhook, and the QR to link the phone with.
     *
     * @param list<string> $webhookEvents
     *
     * @return array{session: array<string, mixed>, connection: array<string, mixed>}
     */
    public function createAndConnectSession(
        string $name,
        string $phoneNumber,
        string $webhookUrl,
        array $webhookEvents
    ): array {
        $response = $this->client->post('/api/whatsapp-sessions', [
            'name' => $name,
            'phone_number' => $phoneNumber,
            'account_protection' => true,
            'log_messages' => true,
            'webhook_url' => $webhookUrl,
            'webhook_enabled' => true,
            'webhook_events' => $webhookEvents,
        ]);
        $session = $response['data'] ?? $response;

        if (! isset($session['id'])) {
            throw new ValidationException('Failed to create WhatsApp session: Session ID not found in response');
        }

        return [
            'session' => $session,
            'connection' => $this->connectSession((int) $session['id'], true),
        ];
    }

    public function getSession(int $sessionId): array
    {
        $response = $this->client->get("/api/whatsapp-sessions/{$sessionId}");

        return $response['data'] ?? $response;
    }

    public function updateSession(int $sessionId, array $data): array
    {
        return $this->client->put("/api/whatsapp-sessions/{$sessionId}", $data);
    }

    public function deleteSession(int $sessionId): array
    {
        return $this->client->delete("/api/whatsapp-sessions/{$sessionId}");
    }

    /**
     * Generates a new QR for the session.
     */
    public function connectSession(int $sessionId, bool $qrAsImage = false): array
    {
        $response = $this->client->post(
            "/api/whatsapp-sessions/{$sessionId}/connect",
            $qrAsImage ? ['qr_as_image' => true] : []
        );

        return $response['data'] ?? $response;
    }

    public function getSessionQrCode(int $sessionId): array
    {
        $response = $this->client->get("/api/whatsapp-sessions/{$sessionId}/qr-code");

        return $response['data'] ?? $response;
    }

    public function disconnectSession(int $sessionId): array
    {
        return $this->client->post("/api/whatsapp-sessions/{$sessionId}/disconnect", []);
    }
}
