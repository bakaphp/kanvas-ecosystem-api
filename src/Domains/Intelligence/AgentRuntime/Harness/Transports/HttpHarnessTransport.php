<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Transports;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\HarnessTransport;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Override;

/**
 * Direct HTTP, used whenever the API can route to the container — same Docker network (addressed by
 * container name, nothing published) or a private address on another host. Verified against a real
 * container: the app container drove a full session this way with no SSH involved.
 */
class HttpHarnessTransport implements HarnessTransport
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $password,
        private readonly string $username = 'opencode',
        private readonly ?GuzzleClient $client = null,
    ) {
    }

    #[Override]
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        int $timeout = 30
    ): array {
        // Built per call: a Guzzle handler stack does not survive queue serialization, and the poller
        // is a queued job that rebuilds its harness every tick.
        $client = $this->client ?? new GuzzleClient([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
            'auth' => [$this->username, $this->password],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        try {
            $response = $client->request($method, ltrim($path, '/'), array_filter([
                'json' => $body,
                'timeout' => $timeout,
                'http_errors' => false,
            ], static fn (mixed $value): bool => $value !== null));
        } catch (GuzzleException $e) {
            throw new HarnessTransportException(
                'Harness request failed (' . $method . ' ' . $path . '): ' . $e->getMessage()
            );
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status >= 400) {
            throw new HarnessTransportException(
                'Harness returned HTTP ' . $status . ' for ' . $method . ' ' . $path . ': ' . mb_substr($raw, 0, 300)
            );
        }

        return self::decode($raw);
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    public static function decode(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
