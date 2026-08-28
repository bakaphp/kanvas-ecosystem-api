<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tavily;

use Baka\Contracts\AppInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Tavily\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Throwable;

/**
 * Tavily REST client — search, extract, crawl and map.
 *
 * The key rides in an `Authorization: Bearer` header rather than the request body. Tavily's original
 * scheme put `api_key` in the JSON payload and still honours it, but it is no longer documented and it
 * puts the credential somewhere request logging is far more likely to capture.
 *
 * Every method returns Tavily's decoded payload untouched. Shaping for an LLM (truncation, dropping
 * fields, flattening) belongs in the tools, which know their own context budget.
 */
class Client
{
    protected string $baseUrl = 'https://api.tavily.com';
    protected string $apiKey;

    public function __construct(AppInterface $app)
    {
        // Settings round-trip through json_decode, so a key stored as false/'' reads back as int 0.
        $key = $app->get(ConfigurationEnum::TAVILY_API_KEY->value);
        $key = is_scalar($key) ? trim((string) $key) : '';

        if ($key === '' || $key === '0') {
            throw new ValidationException('Tavily API key is not set for app ' . $app->getId());
        }

        $this->apiKey = $key;
    }

    /**
     * @param array<string, mixed> $options Any documented /search parameter — topic, time_range,
     *                                      max_results, include_domains, search_depth, ...
     * @return array<string, mixed>
     */
    public function search(string $query, array $options = []): array
    {
        return $this->post('/search', array_merge(
            [
                'search_depth' => 'advanced',
                'topic' => 'general',
                'chunks_per_source' => 3,
                'max_results' => 5,
                'include_answer' => true,
            ],
            $options,
            ['query' => $query],
        ));
    }

    /**
     * Tavily accepts up to 20 URLs per call.
     *
     * @param array<array-key, string>|string $urls
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function extract(array|string $urls, array $options = []): array
    {
        return $this->post(
            '/extract',
            array_merge(
                [
                    'extract_depth' => 'basic',
                    'format' => 'markdown',
                ],
                $options,
                ['urls' => is_array($urls) ? array_values($urls) : [$urls]],
            ),
            timeout: 90,
        );
    }

    /**
     * `allow_external` defaults to false against Tavily's own `true`: a crawl that wanders onto other
     * domains bills for pages nobody asked about and buries the ones that were.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function crawl(string $url, array $options = []): array
    {
        return $this->post(
            '/crawl',
            array_merge(
                [
                    'extract_depth' => 'basic',
                    'format' => 'markdown',
                    'max_depth' => 1,
                    'limit' => 10,
                    'allow_external' => false,
                ],
                $options,
                ['url' => $url],
            ),
            timeout: 180,
        );
    }

    /**
     * The same traversal as crawl but returning only the URLs found — cheap reconnaissance for
     * deciding what is worth extracting.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function map(string $url, array $options = []): array
    {
        return $this->post(
            '/map',
            array_merge(
                [
                    'max_depth' => 1,
                    'limit' => 50,
                    'allow_external' => false,
                ],
                $options,
                ['url' => $url],
            ),
            timeout: 180,
        );
    }

    public static function validateCredentials(string $key): bool
    {
        try {
            /** @var Response $response */
            $response = Http::withToken($key)
                ->timeout(10)
                ->acceptJson()
                ->post('https://api.tavily.com/search', [
                    'query' => 'test',
                    'max_results' => 1,
                ]);

            return $response->successful() && is_array($response->json('results'));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $endpoint, array $payload, int $timeout = 30): array
    {
        try {
            /** @var Response $response */
            $response = Http::withToken($this->apiKey)
                ->timeout($timeout)
                ->acceptJson()
                ->post($this->baseUrl . $endpoint, $payload);
        } catch (Throwable $e) {
            throw new ValidationException('Tavily ' . $endpoint . ' request failed: ' . $e->getMessage());
        }

        return $this->handleResponse($response, $endpoint);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(Response $response, string $endpoint): array
    {
        if ($response->failed()) {
            // Tavily nests the reason under `detail.error`; the bare body covers gateway failures
            // that never reached the API.
            $detail = $response->json('detail.error')
                ?? $response->json('error')
                ?? $response->body();

            throw new ValidationException(sprintf(
                'Tavily %s failed (HTTP %d): %s',
                $endpoint,
                $response->status(),
                is_string($detail) ? $detail : (string) json_encode($detail),
            ));
        }

        return $response->json() ?? [];
    }
}
