<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tavily;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Connectors\Tavily\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $baseUrl = 'https://api.tavily.com';
    protected string $apiKey;
    protected GuzzleClient $httpClient;

    public function __construct(AppInterface $app)
    {
        $key = $app->get(ConfigurationEnum::TAVILY_API_KEY->value);

        if (empty($key)) {
            throw new ValidationException('Tavily API key is not set for ' . $app->name);
        }

        $this->apiKey = (string) $key;
        $this->httpClient = new GuzzleClient(['timeout' => 30]);
    }

    /**
     * Search the web using Tavily.
     * Returns the synthesized answer (when available) followed by source snippets.
     *
     * @param  int  $maxResults  Maximum number of source results to include (1–10).
     */
    public function search(string $query, int $maxResults = 5): string
    {
        try {
            $response = $this->httpClient->post($this->baseUrl . '/search', [
                'json' => [
                    'api_key' => $this->apiKey,
                    'query' => $query,
                    'search_depth' => 'advanced',
                    'max_results' => max(1, min(10, $maxResults)),
                    'include_answer' => true,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true) ?? [];

            $answer = trim((string) ($data['answer'] ?? ''));
            $results = $data['results'] ?? [];

            if (empty($answer) && empty($results)) {
                return '';
            }

            $parts = [];

            if (! empty($answer)) {
                $parts[] = $answer;
            }

            foreach ($results as $result) {
                $title = $result['title'] ?? '';
                $content = $result['content'] ?? '';
                $url = $result['url'] ?? '';

                if (! empty($content)) {
                    $parts[] = "[{$title}]({$url}): {$content}";
                }
            }

            return implode("\n\n", $parts);
        } catch (RequestException $e) {
            throw new ValidationException('Tavily API request failed: ' . $e->getMessage());
        }
    }

    public static function validateCredentials(string $key): bool
    {
        try {
            $client = new GuzzleClient(['timeout' => 10]);
            $response = $client->post('https://api.tavily.com/search', [
                'json' => [
                    'api_key' => $key,
                    'query' => 'test',
                    'max_results' => 1,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data['results']);
        } catch (RequestException) {
            return false;
        }
    }
}
