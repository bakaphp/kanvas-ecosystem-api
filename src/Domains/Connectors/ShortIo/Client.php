<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ShortIo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\ShortIo\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use RuntimeException;
use SensitiveParameter;

/**
 * short.io takes the secret API key as the bare Authorization header value, no Bearer prefix.
 */
class Client
{
    public function __construct(
        #[SensitiveParameter]
        private readonly string $apiKey,
        private readonly ?string $domain,
    ) {
    }

    /**
     * Short timeout: this runs inline on every send, and the caller falls back to the long URL.
     */
    public function createLink(string $originalUrl): string
    {
        $response = $this->request(2)->post(ConfigurationEnum::API_URL . '/links', [
            'originalURL' => $originalUrl,
            'domain' => $this->domain,
        ]);

        $shortUrl = $response->json('shortURL');

        if (! $response->successful() || ! is_string($shortUrl) || $shortUrl === '') {
            throw new RuntimeException('short.io did not create the link (HTTP ' . $response->status() . ').');
        }

        return $shortUrl;
    }

    /**
     * @return list<string> lowercased hostnames (ASCII and unicode) of the account's domains
     */
    public function domainHostnames(): array
    {
        $response = $this->request(10)->get(ConfigurationEnum::API_URL . '/api/domains', ['limit' => 300]);

        if (in_array($response->status(), [401, 403], true)) {
            throw new ValidationException('short.io rejected the API key.');
        }

        $domains = $response->json();
        if (! $response->successful() || ! is_array($domains)) {
            throw new ValidationException('short.io could not list the account domains (HTTP ' . $response->status() . ').');
        }

        $hostnames = [];
        foreach ($domains as $domain) {
            foreach (['hostname', 'unicodeHostname'] as $field) {
                if (is_array($domain) && is_string($domain[$field] ?? null)) {
                    $hostnames[] = strtolower($domain[$field]);
                }
            }
        }

        return array_values(array_unique($hostnames));
    }

    private function request(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)
            ->acceptJson()
            ->withHeaders(['Authorization' => $this->apiKey]);
    }
}
