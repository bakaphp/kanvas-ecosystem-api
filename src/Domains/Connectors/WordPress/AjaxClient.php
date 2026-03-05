<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Kanvas\Exceptions\ValidationException;
use Throwable;

class AjaxClient
{
    protected const int PER_PAGE = 24;
    protected const int MAX_RETRIES = 5;
    protected const int RETRY_DELAY_429_MS = 15000;
    protected const int RETRY_DELAY_TIMEOUT_MS = 5000;
    protected const int REQUEST_TIMEOUT_SECONDS = 45;
    protected const int PAGE_DELAY_SECONDS = 3;

    public function __construct(
        protected string $baseUrl,
    ) {
        if (empty($this->baseUrl)) {
            throw new ValidationException('WordPress base URL is required');
        }

        // Strip www. to avoid 301 redirects that drop POST body
        $this->baseUrl = (string) preg_replace('#^(https?://)www\.#i', '$1', $this->baseUrl);
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
            $data = $this->fetchPage($page, $filterMake);
            $pagination = $data['pagination'] ?? [];

            if ($page === 1 && isset($pagination['total_pages'])) {
                $totalPages = (int) $pagination['total_pages'];
            }

            $vehicles = $data['vehicles'] ?? [];

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

    protected function fetchPage(int $page, ?string $filterMake = null): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/wp-admin/admin-ajax.php';

        $formData = [
            'action' => 'get_inventory_results_v2',
            'allFilters[per_page]' => (string) self::PER_PAGE,
            'allFilters[sort]' => 'photos_is_stock',
            'allFilters[sort_direction]' => 'desc',
            'allFilters[has_photos]' => '1',
            'allFilters[current_page]' => (string) $page,
        ];

        if ($filterMake !== null) {
            $formData['allFilters[make]'] = $filterMake;
        }

        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
            'Referer' => rtrim($this->baseUrl, '/') . '/inventario/',
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
            ->asForm()
            ->post($endpoint, $formData);

        if ($response->failed()) {
            throw new ValidationException(
                'WordPress AJAX request failed (HTTP ' . $response->status() . ')'
            );
        }

        $json = $response->json();

        if (! is_array($json) || ! ($json['success'] ?? false)) {
            throw new ValidationException('Invalid response from WordPress AJAX endpoint');
        }

        $data = $json['data'] ?? [];
        $html = (string) ($data['vehicles'] ?? '');
        $pagination = $data['pagination'] ?? [];

        return [
            'vehicles' => $this->parseVehicleCards($html),
            'pagination' => $pagination,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function parseVehicleCards(string $html): array
    {
        /** @var list<array<string, mixed>> $vehicles */
        $vehicles = [];

        preg_match_all(
            '/<article\s+class="inv360VehicleCard"[^>]*data-vehicle-id="(\d+)"(.*?)<\/article>/s',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $card = $match[2];
            $vehicle = $this->parseCard($card);
            if ($vehicle !== null) {
                $vehicles[] = $vehicle;
            }
        }

        return $vehicles;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseCard(string $card): ?array
    {
        $link = $this->extractMatch('/<a[^>]*class="inv360VehicleCard__goDetail"[^>]*href="([^"]+)"/', $card);
        if ($link === '') {
            $link = $this->extractMatch('/href="([^"]+)"/', $card);
        }

        $title = trim($this->extractMatch('/inv360VehicleCard__title[^>]*>\s*(.+?)\s*<\//s', $card));
        $vin = trim($this->extractMatch('/inv360VehicleCard__icon--vin[^>]*><\/i>\s*<span>\s*(.+?)\s*<\/span>/s', $card));

        if ($vin === '') {
            return null;
        }

        $condition = trim($this->extractMatch('/inv360VehicleCard__info[^>]*>\s*<span>\s*(.+?)\s*<\/span>/s', $card));
        $mileage = trim($this->extractMatch('/inv360VehicleCard__info[^>]*>.*?<span>\s*(.+?)\s*<\/span>\s*<\/p>/s', $card));
        $price = trim($this->extractMatch('/inv360VehicleCard__price[^>]*>([^<]+)/s', $card));
        $engine = trim($this->extractMatch('/inv360VehicleCard__icon--engine[^>]*><\/i>\s*<span>\s*(.+?)\s*<\/span>/s', $card));
        $transmission = trim($this->extractMatch('/inv360VehicleCard__icon--transmission[^>]*><\/i>\s*<span>\s*(.+?)\s*<\/span>/s', $card));
        $mpg = trim($this->extractMatch('/inv360VehicleCard__icon--mpg[^>]*><\/i>\s*<span>\s*(.+?)\s*<\/span>/s', $card));

        // Extract images from swiper slides
        preg_match_all('/inv360VehicleCard__img[^>]*src="([^"]+)"/', $card, $imgMatches);
        $images = $imgMatches[1] ?? [];

        // Parse year, make, model from title (e.g., "Jeep Renegade 2022")
        $year = '';
        $make = '';
        $model = '';
        if (preg_match('/^(.+?)\s+(\d{4})\s*$/', $title, $titleParts)) {
            $year = $titleParts[2];
            $nameParts = explode(' ', $titleParts[1], 2);
            $make = $nameParts[0];
            $model = $nameParts[1] ?? '';
        }

        // Parse year, make, model from URL slug as fallback (e.g., "usado-2022-jeep-renegade-VIN-ID")
        if ($year === '' && preg_match('/\/(nuevo|usado)-(\d{4})-([^\/]+)/', $link, $urlParts)) {
            $condition = $urlParts[1] === 'nuevo' ? 'New' : 'Used';
            $year = $urlParts[2];
            $slugParts = explode('-', $urlParts[3]);
            $make = ucfirst($slugParts[0] ?? '');
            $model = ucfirst($slugParts[1] ?? '');
        }

        // Clean mileage - extract second span content (after condition)
        if (preg_match('/(\d[\d,.]+)\s*millas/', $mileage, $mileageMatch)) {
            $mileage = str_replace(',', '', $mileageMatch[1]);
        } elseif (preg_match('/(\d[\d,.]+)\s*millas/', $card, $mileageMatch)) {
            $mileage = str_replace(',', '', $mileageMatch[1]);
        } else {
            $mileage = '0';
        }

        // Clean price
        $cleanPrice = (string) preg_replace('/[^0-9.]/', '', $price);
        if ($cleanPrice === '' || strtolower($price) === 'llamar') {
            $cleanPrice = '0';
        }

        // Parse MPG
        $mpgCity = '';
        $mpgHwy = '';
        if (preg_match('/(\d+)\/(\d+)/', $mpg, $mpgParts)) {
            $mpgCity = $mpgParts[1];
            $mpgHwy = $mpgParts[2];
        }

        return [
            'title' => $title,
            'vin' => $vin,
            'condition' => $condition,
            'mileage' => $mileage,
            'price' => $cleanPrice,
            'engine' => $engine,
            'transmission' => $transmission,
            'mpg_city' => $mpgCity,
            'mpg_highway' => $mpgHwy,
            'link' => $link,
            'year' => $year,
            'make' => $make,
            'model' => $model,
            'gallery' => ['data' => array_map(fn (string $url) => ['url' => html_entity_decode($url)], $images)],
        ];
    }

    protected function extractMatch(string $pattern, string $subject): string
    {
        if (preg_match($pattern, $subject, $matches)) {
            return html_entity_decode($matches[1] ?? '');
        }

        return '';
    }
}
