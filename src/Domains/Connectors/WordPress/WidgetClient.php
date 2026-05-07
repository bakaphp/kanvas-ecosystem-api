<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Exceptions\ValidationException;

class WidgetClient
{
    protected const int PAGE_SIZE = 18;
    protected const int PAGE_DELAY_SECONDS = 2;
    protected const int REQUEST_TIMEOUT_SECONDS = 45;

    public function __construct(
        protected string $baseUrl,
        protected string $siteId,
        protected string $listingConfigId = 'auto-new',
    ) {
        if (empty($this->baseUrl) || empty($this->siteId)) {
            throw new ValidationException('Widget base URL and site ID are required');
        }
    }

    public function getVehicles(int $start = 0): array
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/api/widget/ws-inv-data/getInventory';

        $referer = rtrim($this->baseUrl, '/') . '/new-inventory/index.htm';

        /** @var Response $response */
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer' => $referer,
            'Origin' => rtrim($this->baseUrl, '/'),
        ])
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->post($endpoint, [
                'siteId' => $this->siteId,
                'locale' => 'en_US',
                'device' => 'DESKTOP',
                'pageAlias' => 'INVENTORY_LISTING_DEFAULT_AUTO_NEW',
                'pageId' => 'v9_INVENTORY_SEARCH_RESULTS_AUTO_NEW_V1_1',
                'windowId' => 'inventory-data-bus2',
                'widgetName' => 'ws-inv-data',
                'inventoryParameters' => [
                    'start' => [(string) $start],
                ],
                'preferences' => [
                    'pageSize' => (string) self::PAGE_SIZE,
                    'listing.config.id' => $this->listingConfigId,
                    'required.display.sets' => 'TITLE,IMAGE_ALT,IMAGE_TITLE,PRICE,FEATURED_ITEMS,CALLOUT,LISTING,HIGHLIGHTED_ATTRIBUTES',
                    'required.display.attributes' => 'askingPrice,bodyStyle,cityMpg,driveLine,engine,engineSize,extColor,exteriorColor,fuelType,highwayMpg,intColor,interiorColor,internetPrice,make,mileage,model,msrp,odometer,salePrice,stockNumber,transmission,trim,type,uuid,vin,year',
                ],
                'includePricing' => true,
            ]);

        if ($response->failed()) {
            throw new ValidationException(
                'Widget API request failed (HTTP ' . $response->status() . ')'
            );
        }

        $data = $response->json();
        if (! is_array($data) || ! isset($data['inventory'])) {
            throw new ValidationException('Invalid widget API response');
        }

        return $data;
    }

    public function getAllVehicles(?callable $onPage = null): array
    {
        /** @var list<array<string, mixed>> $allVehicles */
        $allVehicles = [];
        /** @var array<string, bool> $seenVins */
        $seenVins = [];
        $start = 0;
        $totalCount = 1;
        $pageNumber = 0;

        do {
            $data = $this->getVehicles($start);
            $inventory = $data['inventory'] ?? [];
            /** @var array{totalCount: int, pageSize: int} $pageInfo */
            $pageInfo = $data['pageInfo'] ?? [];
            $totalCount = $pageInfo['totalCount'] ?? 0;
            $totalPages = $totalCount > 0 ? (int) ceil($totalCount / self::PAGE_SIZE) : 1;

            if (count($inventory) === 0) {
                break;
            }

            foreach ($inventory as $vehicle) {
                /** @var array<string, mixed> $vehicle */
                $vin = (string) ($vehicle['vin'] ?? '');
                if ($vin === '' || isset($seenVins[$vin])) {
                    continue;
                }

                $seenVins[$vin] = true;
                $allVehicles[] = $vehicle;
            }

            $pageNumber++;

            if ($onPage !== null) {
                $onPage($pageNumber, $totalPages, count($inventory), count($allVehicles));
            }

            $start += self::PAGE_SIZE;

            if ($start < $totalCount) {
                sleep(self::PAGE_DELAY_SECONDS);
            }
        } while ($start < $totalCount);

        return [
            'vehicles' => $allVehicles,
            'total' => count($allVehicles),
        ];
    }
}
