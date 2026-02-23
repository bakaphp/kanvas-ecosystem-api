<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Exceptions\ValidationException;
use Throwable;

class Client
{
    protected const int PER_PAGE = 24;
    protected const int MAX_RETRIES = 3;
    protected const int RETRY_DELAY_429_MS = 10000;
    protected const int RETRY_DELAY_TIMEOUT_MS = 5000;
    protected const int REQUEST_TIMEOUT_SECONDS = 45;
    protected const int PAGE_DELAY_SECONDS = 2;

    public function __construct(
        protected string $baseUrl,
        protected string $apiPath,
    ) {
        if (empty($this->baseUrl)) {
            throw new ValidationException('WordPress base URL is required');
        }

        if (empty($this->apiPath)) {
            throw new ValidationException('WordPress API path is required');
        }
    }

    public function getVehicles(int $page = 1, ?string $filterMake = null): array
    {
        // Send both `page` and `current_page` params to support different WP plugin versions
        $params = [
            'per_page' => self::PER_PAGE,
            'sort' => 'photos_is_stock',
            'sort_direction' => 'DESC',
            'display' => 'grid',
            'page' => $page,
            'current_page' => $page,
        ];

        if ($filterMake !== null) {
            $params['make'] = $filterMake;
        }

        $endpoint = rtrim($this->baseUrl, '/') . '/' . ltrim($this->apiPath, '/');

        /** @var Response $response */
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
            'Referer' => rtrim($this->baseUrl, '/') . '/inventario-nuevos/',
        ])
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->retry(
                self::MAX_RETRIES,
                function (int $attempt, Throwable $exception) {
                    if ($exception instanceof ConnectionException) {
                        return self::RETRY_DELAY_TIMEOUT_MS;
                    }

                    return self::RETRY_DELAY_429_MS;
                },
                function (Throwable $exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException && $exception->response->status() === 429) {
                        return true;
                    }

                    return false;
                }
            )
            ->get($endpoint, $params);

        return $this->parseResponse($response);
    }

    public function getAllVehicles(?string $filterMake = null, ?callable $onPage = null): array
    {
        /** @var list<array<string, mixed>> $allVehicles */
        $allVehicles = [];
        /** @var array<string, bool> $seenVins */
        $seenVins = [];
        $page = 1;
        $totalPages = 1;

        while ($page <= $totalPages) {
            $data = $this->getVehicles($page, $filterMake);
            /** @var list<array<string, mixed>> $vehicles */
            $vehicles = $data['data'] ?? [];
            $pagination = $data['pagination'] ?? [];

            if ($page === 1 && isset($pagination['total_pages'])) {
                $totalPages = (int) $pagination['total_pages'];
            }

            if (count($vehicles) === 0) {
                break;
            }

            foreach ($vehicles as $vehicle) {
                $vin = (string) ($vehicle['vin'] ?? '');
                if ($vin === '' || isset($seenVins[$vin])) {
                    continue;
                }

                $seenVins[$vin] = true;
                $allVehicles[] = $vehicle;
            }

            if ($onPage !== null) {
                $onPage($page, $totalPages, count($vehicles), count($allVehicles));
            }

            $page++;

            if ($page <= $totalPages) {
                sleep(self::PAGE_DELAY_SECONDS);
            }
        }

        return [
            'vehicles' => $allVehicles,
            'total' => count($allVehicles),
        ];
    }

    protected function parseResponse(Response $response): array
    {
        if ($response->failed()) {
            throw new ValidationException(
                'WordPress API request failed (HTTP ' . $response->status() . ')'
            );
        }

        $body = $response->body();

        // Strip HTML wrappers (some WP sites wrap JSON in <pre> tags)
        $body = (string) preg_replace('/^.*?<pre[^>]*>/s', '', $body);
        $body = (string) preg_replace('/<\/pre>.*$/s', '', $body);
        $body = trim($body);

        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new ValidationException('Invalid JSON response from WordPress API');
        }

        return $data;
    }
}
