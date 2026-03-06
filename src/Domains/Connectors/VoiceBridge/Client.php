<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    private const MAX_INSTANCES = 100;
    private static array $instances = [];

    protected string $baseUrl;
    protected string $apiKey;
    protected GuzzleClient $client;

    private function __construct(protected AppInterface $app)
    {
        $this->apiKey = $app->get(ConfigurationEnum::API_KEY->value);
        $this->baseUrl = $app->get(ConfigurationEnum::BASE_URL->value);

        if (empty($this->apiKey) || empty($this->baseUrl)) {
            throw new ValidationException('VoiceBridge configuration is missing. Please run the setup first.');
        }

        $this->client = new GuzzleClient([
            'base_uri' => rtrim($this->baseUrl, '/'),
            'headers' => [
                'X-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public static function getInstance(AppInterface $app): self
    {
        $key = sprintf('app_%s', $app->getId());

        if (! isset(self::$instances[$key])) {
            self::$instances[$key] = new self($app);
            self::cleanupOldInstances();
        }

        return self::$instances[$key];
    }

    /**
     * Validate API credentials by hitting the health endpoint (no auth required).
     */
    public static function validateCredentials(string $apiKey, string $baseUrl): bool
    {
        try {
            $client = new GuzzleClient([
                'base_uri' => rtrim($baseUrl, '/'),
                'headers' => [
                    'X-API-Key' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = $client->get('/health');

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            throw new ValidationException(
                'Failed to connect to VoiceBridge API: ' . $e->getMessage(),
                $e->getCode()
            );
        }
    }

    /**
     * Initialize a voice session with context data.
     * If the session already exists, returns the current state without overwriting it.
     */
    public function initSession(string $userId, string $sessionId, array $initialContext = []): array
    {
        $payload = [
            'user_id' => $userId,
            'session_id' => $sessionId,
        ];

        if (! empty($initialContext)) {
            $payload['initial_context'] = $initialContext;
        }

        return $this->post('/api/kanvas/init-session', $payload);
    }

    /**
     * Trigger an outbound call to the specified phone number.
     * Uses Twilio credentials of the company configured in the session.
     */
    public function triggerCall(
        string $userId,
        string $sessionId,
        string $phoneNumber,
        ?string $companyId = null
    ): array {
        $payload = [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'phone_number' => $phoneNumber,
        ];

        if ($companyId !== null) {
            $payload['company_id'] = $companyId;
        }

        return $this->post('/api/kanvas/trigger-call', $payload);
    }

    /**
     * List recent sessions with optional filters.
     * Params: type (incoming|outbound), company_id, limit (1-100).
     */
    public function getSessions(array $params = []): array
    {
        return $this->get('/api/kanvas/sessions', $params);
    }

    /**
     * Get sessions currently in progress (calls that haven't ended yet).
     * Params: company_id, limit (1-100).
     */
    public function getActiveSessions(array $params = []): array
    {
        return $this->get('/api/kanvas/sessions/active', $params);
    }

    /**
     * Get a session with its full conversation transcript.
     */
    public function getSessionTranscript(string $sessionId): array
    {
        return $this->get('/api/kanvas/sessions/' . urlencode($sessionId));
    }

    /**
     * Get session transcript with is_active flag for polling during active calls.
     */
    public function getTranscriptStream(string $sessionId): array
    {
        return $this->get('/api/kanvas/sessions/' . urlencode($sessionId) . '/transcript/stream');
    }

    /**
     * Find all sessions associated with a phone number (supports partial matching).
     */
    public function searchSessionsByPhone(string $phoneNumber): array
    {
        return $this->get('/api/kanvas/sessions/phone/' . urlencode($phoneNumber));
    }

    /**
     * Get current session state in agent runtime and whether the call is active.
     */
    public function getSessionState(string $userId, string $sessionId): array
    {
        return $this->get('/api/kanvas/session-state/' . urlencode($userId) . '/' . urlencode($sessionId));
    }

    /**
     * Completely delete a session from runtime memory and registry (hard delete, irreversible).
     */
    public function deleteSession(string $userId, string $sessionId): array
    {
        return $this->delete('/api/kanvas/session-state/' . urlencode($userId) . '/' . urlencode($sessionId));
    }

    protected function get(string $endpoint, array $queryParams = []): array
    {
        try {
            $options = [];
            if (! empty($queryParams)) {
                $options['query'] = $queryParams;
            }
            $response = $this->client->get($endpoint, $options);

            return json_decode($response->getBody()->getContents(), true);
        } catch (BadResponseException $e) {
            $body = $e->getResponse()->getBody()->getContents();
            $error = json_decode($body, true);

            throw new ValidationException(
                $error['detail'] ?? $error['message'] ?? $e->getMessage(),
                (int) ($error['code'] ?? $e->getCode())
            );
        }
    }

    protected function post(string $endpoint, array $data): array
    {
        try {
            $response = $this->client->post($endpoint, ['json' => $data]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (BadResponseException $e) {
            $body = $e->getResponse()->getBody()->getContents();
            $error = json_decode($body, true);

            throw new ValidationException(
                $error['detail'] ?? $error['message'] ?? $e->getMessage(),
                (int) ($error['code'] ?? $e->getCode())
            );
        }
    }

    protected function delete(string $endpoint): array
    {
        try {
            $response = $this->client->delete($endpoint);

            return json_decode($response->getBody()->getContents(), true);
        } catch (BadResponseException $e) {
            $body = $e->getResponse()->getBody()->getContents();
            $error = json_decode($body, true);

            throw new ValidationException(
                $error['detail'] ?? $error['message'] ?? $e->getMessage(),
                (int) ($error['code'] ?? $e->getCode())
            );
        }
    }

    private static function cleanupOldInstances(): void
    {
        if (count(self::$instances) > self::MAX_INSTANCES) {
            array_shift(self::$instances);
        }
    }
}
