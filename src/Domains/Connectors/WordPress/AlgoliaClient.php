<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Exceptions\ValidationException;

class AlgoliaClient
{
    protected const int PER_PAGE = 24;
    protected const int PAGE_DELAY_SECONDS = 1;

    public function __construct(
        protected string $applicationId,
        protected string $apiKey,
        protected string $indexName,
    ) {
        if (empty($this->applicationId) || empty($this->apiKey) || empty($this->indexName)) {
            throw new ValidationException('Algolia application ID, API key, and index name are required');
        }
    }

    public function getVehicles(int $page = 0): array
    {
        $queryParams = http_build_query([
            'x-algolia-agent' => 'Algolia for JavaScript (4.9.1); Browser (lite)',
            'x-algolia-api-key' => $this->apiKey,
            'x-algolia-application-id' => $this->applicationId,
        ]);

        $endpoint = "https://{$this->applicationId}-dsn.algolia.net/1/indexes/*/queries?{$queryParams}";

        /** @var Response $response */
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($endpoint, [
            'requests' => [
                [
                    'indexName' => $this->indexName,
                    'params' => 'hitsPerPage=' . self::PER_PAGE . '&page=' . $page,
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new ValidationException(
                'Algolia API request failed (HTTP ' . $response->status() . ')'
            );
        }

        /** @var array{results: list<array<string, mixed>>}|null $data */
        $data = $response->json();
        if (! is_array($data) || ! isset($data['results'][0])) {
            throw new ValidationException('Invalid Algolia response');
        }

        return $data['results'][0];
    }

    public function getAllVehicles(?callable $onPage = null): array
    {
        /** @var list<array<string, mixed>> $allVehicles */
        $allVehicles = [];
        /** @var array<string, bool> $seenVins */
        $seenVins = [];
        $page = 0;
        $totalPages = 1;

        while ($page < $totalPages) {
            $result = $this->getVehicles($page);
            $hits = $result['hits'] ?? [];
            $totalPages = (int) ($result['nbPages'] ?? 1);

            if (count($hits) === 0) {
                break;
            }

            foreach ($hits as $hit) {
                /** @var array<string, mixed> $hit */
                $vin = (string) ($hit['vin'] ?? '');
                if ($vin === '' || isset($seenVins[$vin])) {
                    continue;
                }

                $seenVins[$vin] = true;
                $allVehicles[] = $hit;
            }

            if ($onPage !== null) {
                $onPage($page + 1, $totalPages, count($hits), count($allVehicles));
            }

            $page++;

            if ($page < $totalPages) {
                sleep(self::PAGE_DELAY_SECONDS);
            }
        }

        return [
            'vehicles' => $allVehicles,
            'total' => count($allVehicles),
        ];
    }
}
