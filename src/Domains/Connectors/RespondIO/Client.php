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

/**
 * A contact is named by an identifier: `phone:+1234567890`, `id:123` or `email:user@example.com`.
 */
class Client
{
    protected string $baseUrl = 'https://api.respond.io/v2';
    protected string $bearerToken;

    /**
     * Respond.io's limit, per method and path.
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

    public function post(string $path, array $data = []): array
    {
        $this->throttle('POST', $path);

        return $this->handleResponse(
            Http::withToken($this->bearerToken)->post($this->baseUrl . $path, $data)
        );
    }

    public function sendMessage(string $phone, string $message): array
    {
        return $this->post('/contact/phone:' . Str::ensurePhonePrefix($phone) . '/message', [
            'message' => [
                'type' => 'text',
                'text' => $message,
            ],
        ]);
    }

    public function sendAttachment(
        string $phone,
        string $type,
        string $url,
        ?string $caption = null
    ): array {
        return $this->post('/contact/phone:' . Str::ensurePhonePrefix($phone) . '/message', [
            'message' => [
                'type' => 'attachment',
                'attachment' => array_filter(
                    [
                        'type' => $type,
                        'url' => $url,
                        'caption' => $caption,
                    ],
                    static fn (?string $value): bool => $value !== null
                ),
            ],
        ]);
    }

    /**
     * @param string $status "open" or "close"; the closing note only goes with "close"
     */
    public function updateConversationStatus(
        string $identifier,
        string $status,
        ?string $closingNoteCategory = null,
        ?string $closingNoteSummary = null
    ): array {
        $data = ['status' => $status];

        if ($status === 'close') {
            if ($closingNoteCategory !== null) {
                $data['closingNote']['category'] = $closingNoteCategory;
            }
            if ($closingNoteSummary !== null) {
                $data['closingNote']['summary'] = $closingNoteSummary;
            }
        }

        return $this->post("/contact/{$identifier}/conversation/status", $data);
    }

    public function createOrUpdateContact(string $identifier, array $data): array
    {
        return $this->post("/contact/create_or_update/{$identifier}", $data);
    }

    /**
     * @param array<string> $tags 1-10 tags, max 255 chars each
     */
    public function addContactTags(string $identifier, array $tags): array
    {
        return $this->post("/contact/{$identifier}/tag", ['tags' => $tags]);
    }

    public static function validateCredentials(string $bearerToken): bool
    {
        try {
            return Http::withToken($bearerToken)
                ->get('https://api.respond.io/v2/space/user')
                ->successful();
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Any answer that is not a success is a refusal, a rate limit included: the caller decides
     * whether to try again, so nothing waits here.
     */
    protected function handleResponse(Response $response): array
    {
        if ($response->status() === 429) {
            throw new ValidationException(
                'Respond.io rate limit exceeded. Retry after ' . max(1, (int) $response->header('Retry-After')) . ' seconds.'
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
     * Waits for a free slot instead of spending a request on a 429.
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
            sleep(max(1, RateLimiter::availableIn($key)));
        }
    }
}
