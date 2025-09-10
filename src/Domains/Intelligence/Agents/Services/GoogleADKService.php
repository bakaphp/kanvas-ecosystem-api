<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Http;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Enums\ConfigurationEnum;

class GoogleADKService
{
    protected GuzzleClient $client;
    protected string $baseUrl;
    protected string $apiKey;
    protected string $appName;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected ?string $agent = null
    ) {
        $this->baseUrl = $this->app->get(ConfigurationEnum::ADK_BASE_URL->value);
        $this->apiKey = $this->app->get(ConfigurationEnum::ADK_API_KEY->value);
        $this->appName = $this->agent ?? $this->app->get(ConfigurationEnum::ADK_APP_NAME->value) ?? 'orchestrate';

        if (empty($this->baseUrl)) {
            throw new ValidationException('Google Orchestrator configuration is missing');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'verify' => false,
        ]);
    }

    /**
     * Start a new session for a user
     *
     * @throws GuzzleException
     */
    public function startSession(string $userId, string $sessionId): array
    {
        $endpoint = "apps/{$this->appName}/users/{$userId}/sessions/{$sessionId}";

        try {
            $response = $this->client->post($endpoint);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (ClientException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $responseData = json_decode($responseBody, true);

            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 400 && isset($responseData['detail']) && str_contains($responseData['detail'], 'Session already exists')) {
                // Return a specific response or handle as needed
                return [
                    'error' => true,
                    'message' => $responseData['detail'],
                    'session_id' => $sessionId,
                ];
            }

            throw $e;
        }
    }

    /**
     * Send a chat message and get streaming response
     *
     * @param callable|null $onChunk Callback function to process each chunk
     * @return string Complete response text
     * @throws GuzzleException
     */
    public function chat(
        string $userId,
        string $sessionId,
        string $message,
        ?callable $onChunk = null
    ): string {
        $response = Http::withHeaders([
            'Accept' => 'text/event-stream',
            'Content-Type' => 'application/json',
        ])->withOptions([
            'stream' => true,
        ])->post($this->baseUrl . '/run_sse', [
            'app_name' => $this->appName,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'new_message' => [
                'role' => 'user',
                'parts' => [['text' => $message]],
            ],
        ]);

        $completeResponse = '';
        $buffer = ''; // Buffer to handle incomplete JSON across chunks
        $stream = $response->toPsrResponse()->getBody();

        while (! $stream->eof()) {
            $chunk = $stream->read(1024);

            if ($chunk) {
                $buffer .= $chunk;

                // Process complete lines ending with \n
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    $line = trim($line);

                    // Skip empty lines
                    if (empty($line)) {
                        continue;
                    }

                    // Handle SSE format - remove "data: " prefix
                    if (strpos($line, 'data: ') === 0) {
                        $jsonString = substr($line, 6); // Remove "data: " prefix

                        // Skip empty data lines
                        if (empty($jsonString)) {
                            continue;
                        }

                        // Try to decode JSON
                        $data = json_decode($jsonString, true);

                        if ($data) {
                            // Check for error messages
                            if (isset($data['error'])) {
                                throw new Exception('API Error: ' . $data['error']);
                            }

                            // Process content parts
                            if (isset($data['content']['parts'])) {
                                foreach ($data['content']['parts'] as $part) {
                                    if (isset($part['text'])) {
                                        $text = $part['text'];
                                        $completeResponse .= $text;

                                        // Call the callback if provided
                                        if ($onChunk) {
                                            $onChunk($text, $data);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $completeResponse;
    }

    /**
     * Send a chat message with simple response (non-streaming)
     *
     * @throws GuzzleException
     */
    public function chatSimple(string $userId, string $sessionId, string $message): array
    {
        $response = $this->client->post('/run', [
            'json' => [
                'app_name' => $this->appName,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'new_message' => [
                    'role' => 'user',
                    'parts' => [['text' => $message]],
                ],
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    /**
     * Get session history
     *
     * @throws GuzzleException
     */
    public function getSessionHistory(string $userId, string $sessionId): array
    {
        $endpoint = "apps/{$this->appName}/users/{$userId}/sessions/{$sessionId}";

        $response = $this->client->get($endpoint);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    /**
     * Delete a session
     *
     * @throws GuzzleException
     */
    public function deleteSession(string $userId, string $sessionId): bool
    {
        $endpoint = "apps/{$this->appName}/users/{$userId}/sessions/{$sessionId}";

        $response = $this->client->delete($endpoint);

        return $response->getStatusCode() === 200;
    }

    /**
     * Get all sessions for a user
     *
     * @throws GuzzleException
     */
    public function getUserSessions(string $userId): array
    {
        $endpoint = "apps/{$this->appName}/users/{$userId}/sessions";

        $response = $this->client->get($endpoint);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    /**
     * Generate a unique session ID
     */
    public function generateSessionId(): string
    {
        return 's_' . uniqid();
    }

    /**
     * Get the current app name
     */
    public function getAppName(): string
    {
        return $this->appName;
    }

    /**
     * Get the base URL
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
