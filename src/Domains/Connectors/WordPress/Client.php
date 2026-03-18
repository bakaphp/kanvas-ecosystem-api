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
    protected const int MAX_RETRIES = 5;
    protected const int RETRY_DELAY_429_MS = 15000;
    protected const int RETRY_DELAY_TIMEOUT_MS = 5000;
    protected const int REQUEST_TIMEOUT_SECONDS = 45;
    protected const int PAGE_DELAY_SECONDS = 3;

    public function __construct(
        protected string $baseUrl,
        protected string $apiPath,
        protected array $extraParams = [],
    ) {
        if (empty($this->baseUrl)) {
            throw new ValidationException('WordPress base URL is required');
        }

        if (empty($this->apiPath)) {
            throw new ValidationException('WordPress API path is required');
        }

        // Strip www. to avoid 301 redirects that may cause issues
        $this->baseUrl = (string) preg_replace('#^(https?://)www\.#i', '$1', $this->baseUrl);
    }

    public function getVehicles(int $page = 1, ?string $filterMake = null): array
    {
        // Send both `page` and `current_page` params to support different WP plugin versions
        $params = array_merge($this->extraParams, [
            'page' => $page,
            'current_page' => $page,
        ]);

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
        $totalSkippedNoVin = 0;
        $totalSkippedDupeVin = 0;
        /** @var array<string, mixed>|null $sampleNoVin */
        $sampleNoVin = null;
        /** @var array{vin: string, page: int, vehicle: array<string, mixed>}|null $sampleDupeVin */
        $sampleDupeVin = null;

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

            $pageSkippedNoVin = 0;
            $pageSkippedDupeVin = 0;

            foreach ($vehicles as $vehicle) {
                $vin = (string) ($vehicle['vin'] ?? '');
                if ($vin === '') {
                    $pageSkippedNoVin++;
                    $totalSkippedNoVin++;
                    if ($sampleNoVin === null) {
                        $sampleNoVin = $vehicle;
                    }

                    continue;
                }

                if (isset($seenVins[$vin])) {
                    $pageSkippedDupeVin++;
                    $totalSkippedDupeVin++;
                    if ($sampleDupeVin === null) {
                        $sampleDupeVin = ['vin' => $vin, 'page' => $page, 'vehicle' => $vehicle];
                    }

                    continue;
                }

                $seenVins[$vin] = true;
                $allVehicles[] = $vehicle;
            }

            if ($onPage !== null) {
                $onPage($page, $totalPages, count($vehicles), count($allVehicles), $pageSkippedNoVin, $pageSkippedDupeVin);
            }

            $page++;

            if ($page <= $totalPages) {
                sleep(self::PAGE_DELAY_SECONDS);
            }
        }

        return [
            'vehicles' => $allVehicles,
            'total' => count($allVehicles),
            'skipped_no_vin' => $totalSkippedNoVin,
            'skipped_duplicate_vin' => $totalSkippedDupeVin,
            'sample_no_vin' => $sampleNoVin,
            'sample_duplicate_vin' => $sampleDupeVin,
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
