<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $baseUrl = 'https://api.respond.io/v2';
    protected string $bearerToken;

    /**
     * Max requests per second per method+path (Respond.io limit).
     */
    protected int $maxRequestsPerSecond = 5;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        // Settings round-trip through json_decode, so an all-digit token comes back as int
        // and a token written as false/'' comes back as int 0 — both must not hit a string property.
        $bearerToken = $company->get(ConfigurationEnum::BEARER_TOKEN->value)
            ?? $app->get(ConfigurationEnum::BEARER_TOKEN->value);

        $bearerToken = is_scalar($bearerToken) ? trim((string) $bearerToken) : '';

        if ($bearerToken === '' || $bearerToken === '0') {
            throw new ValidationException('Respond.io bearer token is not set.');
        }

        $this->bearerToken = $bearerToken;
    }

    public function get(string $path, array $params = []): array
    {
        $this->throttle('GET', $path);

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withToken($this->bearerToken)
            ->get($this->baseUrl . $path, $params);

        return $this->handleResponse($response);
    }

    public function post(string $path, array $data = [], array $params = []): array
    {
        $this->throttle('POST', $path);

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withToken($this->bearerToken)
                        ->withOptions($params)
                        ->post($this->baseUrl . $path, $data);

        return $this->handleResponse($response);
    }

    public function put(string $path, array $data = []): array
    {
        $this->throttle('PUT', $path);

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withToken($this->bearerToken)
            ->put($this->baseUrl . $path, $data);

        return $this->handleResponse($response);
    }

    public function delete(string $path, array $data = []): array
    {
        $this->throttle('DELETE', $path);

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withToken($this->bearerToken)
            ->delete($this->baseUrl . $path, $data);

        return $this->handleResponse($response);
    }

    public function sendMessage(string $phone, string $message, array $params = []): array
    {
        $phone = Str::ensurePhonePrefix($phone);

        $path = "/contact/phone:{$phone}/message";
        $data = [
            'message' => [
                'type' => 'text',
                'text' => $message,
            ],
        ];

        return $this->post($path, $data, $params);
    }

    public function sendAttachment(
        string $phone,
        string $type,
        string $url,
        ?string $caption = null
    ): array {
        $phone = Str::ensurePhonePrefix($phone);

        $path = "/contact/phone:{$phone}/message";
        $attachment = [
            'type' => $type,
            'url' => $url,
        ];

        if ($caption !== null) {
            $attachment['caption'] = $caption;
        }

        $data = [
            'message' => [
                'type' => 'attachment',
                'attachment' => $attachment,
            ],
        ];

        return $this->post($path, $data);
    }

    /**
     * Assign a conversation to a user or unassign it.
     *
     * @param string $identifier Contact identifier (e.g., "phone:+1234567890" or "id:123")
     * @param int|string|null $assignee User ID, email, or null to unassign
     */
    public function assignConversation(string $identifier, int|string|null $assignee = null): array
    {
        $path = "/contact/{$identifier}/conversation/assignee";

        return $this->post($path, ['assignee' => $assignee]);
    }

    /**
     * Open or close a conversation.
     *
     * @param string $identifier Contact identifier (e.g., "phone:+1234567890" or "id:123")
     * @param string $status "open" or "close"
     */
    public function updateConversationStatus(
        string $identifier,
        string $status,
        ?string $closingNoteCategory = null,
        ?string $closingNoteSummary = null
    ): array {
        $path = "/contact/{$identifier}/conversation/status";
        $data = ['status' => $status];

        if ($status === 'close') {
            if ($closingNoteCategory !== null) {
                $data['closingNote']['category'] = $closingNoteCategory;
            }
            if ($closingNoteSummary !== null) {
                $data['closingNote']['summary'] = $closingNoteSummary;
            }
        }

        return $this->post($path, $data);
    }

    /**
     * Create or update a contact (upsert).
     *
     * @param string $identifier Contact identifier (e.g., "phone:+1234567890" or "email:user@example.com")
     */
    public function createOrUpdateContact(string $identifier, array $data): array
    {
        $path = "/contact/create_or_update/{$identifier}";

        return $this->post($path, $data);
    }

    /**
     * Get a contact by identifier.
     *
     * @param string $identifier Contact identifier (e.g., "phone:+1234567890", "id:123", "email:user@example.com")
     */
    public function getContact(string $identifier): array
    {
        return $this->get("/contact/{$identifier}");
    }

    /**
     * Add tags to a contact.
     *
     * @param string $identifier Contact identifier
     * @param array<string> $tags 1-10 tags, max 255 chars each
     */
    public function addContactTags(string $identifier, array $tags): array
    {
        return $this->post("/contact/{$identifier}/tag", ['tags' => $tags]);
    }

    /**
     * Remove tags from a contact.
     *
     * @param string $identifier Contact identifier
     * @param array<string> $tags Tags to remove
     */
    public function removeContactTags(string $identifier, array $tags): array
    {
        return $this->delete("/contact/{$identifier}/tag", ['tags' => $tags]);
    }

    public static function validateCredentials(string $bearerToken): bool
    {
        try {
            $response = Http::withToken($bearerToken)
                ->get('https://api.respond.io/v2/space/user');

            return $response->successful();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Handle API response, throwing on errors and respecting rate limits.
     */
    protected function handleResponse(Response $response): array
    {
        if ($response->status() === 429) {
            $retryAfter = max(1, (int) $response->header('Retry-After'));
            sleep(max(1, min($retryAfter, 10)));

            throw new ValidationException(
                'Respond.io rate limit exceeded. Retry after ' . $retryAfter . ' seconds.'
            );
        }

        if (! $response->successful()) {
            /** @var array<string, mixed> $body */
            $body = $response->json() ?? [];
            $errorMessage = (string) ($body['message'] ?? $body['error'] ?? 'Unknown error');

            throw new ValidationException(
                'Respond.io API error (' . $response->status() . '): ' . $errorMessage
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Throttle requests to stay within Respond.io rate limits (5 req/sec per method+path).
     */
    protected function throttle(string $method, string $path): void
    {
        $basePath = preg_replace('/\/(id|phone|email):[^\/]+/', '/{identifier}', $path);
        $key = 'respondio:' . $method . ':' . $basePath;

        $executed = RateLimiter::attempt(
            $key,
            $this->maxRequestsPerSecond,
            fn () => true,
            1
        );

        if (! $executed) {
            $availableIn = RateLimiter::availableIn($key);
            sleep(max(1, $availableIn));
        }
    }
}
