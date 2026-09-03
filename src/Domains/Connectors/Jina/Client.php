<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Jina;

use Baka\Contracts\AppInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Jina\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Throwable;

/**
 * Jina Reader (r.jina.ai) and Search (s.jina.ai).
 *
 * Both wrap their payload in a `{code, status, data}` envelope where the envelope carries no meaning
 * past transport, so these methods return the unwrapped `data` — unlike the Tavily client, whose API
 * has no envelope to strip. A failure comes back as that same envelope with an HTTP error status and a
 * `readableMessage`, which is what `handleResponse` reads.
 *
 * Reader answers keyless at a low rate limit; Search always requires a key. Requiring one for both
 * keeps a tenant from silently depending on the anonymous quota and then hitting 429s under load.
 */
class Client
{
    protected string $readerUrl = 'https://r.jina.ai/';
    protected string $searchUrl = 'https://s.jina.ai/';
    protected string $apiKey;

    public function __construct(AppInterface $app)
    {
        // Settings round-trip through json_decode, so a key stored as false/'' reads back as int 0.
        $key = $app->get(ConfigurationEnum::JINA_API_KEY->value);
        $key = is_scalar($key) ? trim((string) $key) : '';

        if ($key === '' || $key === '0') {
            throw new ValidationException('Jina API key is not set for app ' . $app->getId());
        }

        $this->apiKey = $key;
    }

    /**
     * A single page as markdown.
     *
     * @param array<string, string> $headers Reader controls — `X-Engine: browser` for a scripted page,
     *                                       `X-Target-Selector`, `X-Timeout`, ...
     * @return array<array-key, mixed> title, description, url, content, usage
     */
    public function read(string $url, array $headers = []): array
    {
        return $this->post(
            $this->readerUrl,
            ['url' => $url],
            $headers,
            timeout: 90,
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array<array-key, mixed> One record per result
     */
    public function search(string $query, array $headers = []): array
    {
        return $this->post(
            $this->searchUrl,
            ['q' => $query],
            $headers,
            timeout: 60,
        );
    }

    public static function validateCredentials(string $key): bool
    {
        try {
            // Search rather than Reader: Reader answers anonymously, so it would accept a bad key.
            /** @var Response $response */
            $response = Http::withToken($key)
                ->timeout(15)
                ->acceptJson()
                ->post('https://s.jina.ai/', ['q' => 'test']);

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @return array<array-key, mixed>
     */
    private function post(
        string $url,
        array $payload,
        array $headers,
        int $timeout,
    ): array {
        try {
            /** @var Response $response */
            $response = Http::withToken($this->apiKey)
                ->withHeaders($headers)
                ->timeout($timeout)
                ->acceptJson()
                ->post($url, $payload);
        } catch (Throwable $e) {
            throw new ValidationException('Jina request to ' . $url . ' failed: ' . $e->getMessage());
        }

        return $this->handleResponse($response, $url);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function handleResponse(Response $response, string $url): array
    {
        $code = $response->json('code');

        // Jina reports the real outcome twice — the HTTP status and an envelope `code`. They agree
        // today, but trusting only the status would let a body-level failure through as a success
        // with a null `data`, which reads downstream as "the page was empty".
        if ($response->failed() || (is_int($code) && $code >= 400)) {
            $detail = $response->json('readableMessage')
                ?? $response->json('message')
                ?? $response->body();

            throw new ValidationException(sprintf(
                'Jina %s failed (HTTP %d): %s',
                $url,
                $response->status(),
                is_string($detail) ? $detail : (string) json_encode($detail),
            ));
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }
}
