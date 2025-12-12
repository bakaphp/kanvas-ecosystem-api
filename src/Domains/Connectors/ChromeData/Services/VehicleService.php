<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ChromeData\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Redis;
use Kanvas\Connectors\ChromeData\Client;
use Kanvas\Connectors\ChromeData\DataTransferObject\VehicleData;

class VehicleService
{
    protected Client $client;
    protected string $cachePrefix = 'chromedata:vin:';
    protected string $stockImageCachePrefix = 'chromedata2:stock:';
    protected int $cacheTtl = 259200; // 3 days in seconds

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Get vehicle information by VIN.
     */
    public function getVehicleInfoByVin(
        string $vin,
        bool $includeMediaGallery = false,
        bool $skipCache = false
    ): ?VehicleData {
        $cacheKey = $this->getCacheKey($vin, $includeMediaGallery);

        // Check cache first unless skipCache is true
        if (! $skipCache) {
            $cachedData = $this->getFromCache($cacheKey);
            if ($cachedData !== null) {
                return VehicleData::from($cachedData);
            }
        }

        $switches = [];
        if ($includeMediaGallery) {
            $switches['includeMediaGallery'] = 'Both'; // 'Multi-View', 'ColorMatch', or 'Both'
        }

        $response = $this->client->describeVehicleByVin($vin, $switches);
        $parsedData = $this->parseVehicleDescription($response);

        // Cache the result
        $this->saveToCache($cacheKey, $parsedData);

        return VehicleData::from($parsedData);
    }

    /**
     * Get vehicle information by style ID.
     */
    public function getVehicleInfoByStyleId(
        int $styleId,
        bool $includeMediaGallery = false,
        bool $skipCache = false
    ): ?VehicleData {
        $cacheKey = $this->getCacheKey("style:{$styleId}", $includeMediaGallery);

        // Check cache first unless skipCache is true
        if (! $skipCache) {
            $cachedData = $this->getFromCache($cacheKey);
            if ($cachedData !== null) {
                return VehicleData::from($cachedData);
            }
        }

        $switches = [];
        if ($includeMediaGallery) {
            $switches['includeMediaGallery'] = 'Both';
        }

        $response = $this->client->describeVehicleByStyleId($styleId, $switches);
        $parsedData = $this->parseVehicleDescription($response);

        // Cache the result
        $this->saveToCache($cacheKey, $parsedData);

        return VehicleData::from($parsedData);
    }

    /**
     * Get available model years.
     */
    public function getModelYears(): array
    {
        $response = $this->client->getModelYears();

        return $response->modelYear ?? [];
    }

    /**
     * Get divisions for a specific model year.
     */
    public function getDivisions(int $modelYear): array
    {
        $response = $this->client->getDivisions($modelYear);

        return $this->parseIdentifiedStrings($response->division ?? []);
    }

    /**
     * Get models for a specific model year.
     */
    public function getModels(int $modelYear, ?int $divisionId = null, ?int $subdivisionId = null): array
    {
        $response = $this->client->getModels($modelYear, $divisionId, $subdivisionId);

        return $this->parseIdentifiedStrings($response->model ?? []);
    }

    /**
     * Get styles for a specific model.
     */
    public function getStyles(int $modelId): array
    {
        $response = $this->client->getStyles($modelId);

        return $this->parseIdentifiedStrings($response->style ?? []);
    }

    /**
     * Parse vehicle description from SOAP response.
     */
    protected function parseVehicleDescription(object $response): array
    {
        $vinDesc = $response->vinDescription ?? null;
        $styles = $this->toArray($response->style ?? []);
        $engines = $this->toArray($response->engine ?? []);

        $style = $styles[0] ?? null;
        $engine = $engines[0] ?? null;

        return [
            'vin' => $vinDesc?->vin ?? null,
            'year' => $vinDesc?->modelYear ?? $response->modelYear ?? null,
            'make' => $response->bestMakeName ?? null,
            'model' => $response->bestModelName ?? null,
            'trim' => $response->bestTrimName ?? null,
            'styleName' => $response->bestStyleName ?? null,
            'bodyStyle' => $vinDesc?->bodyType ?? null,
            'driveTrain' => $style?->drivetrain ?? null,
            'passDoors' => $style?->passDoors ?? null,
            'stockImage' => $style?->stockImage?->url ?? null,
            'engine' => $engine !== null ? [
                'cylinders' => $engine->cylinders ?? null,
                'type' => $engine->engineType->_ ?? null,
                'fuelType' => $engine->fuelType->_ ?? null,
                'displacement' => $this->getDisplacement($engine),
                'horsepower' => $engine->horsepower?->value ?? null,
                'torque' => $engine->netTorque?->value ?? null,
            ] : null,
            'exteriorColors' => $this->parseColors($response->exteriorColor ?? []),
            'interiorColors' => $this->parseColors($response->interiorColor ?? []),
            'basePrice' => $this->parsePrice($style?->basePrice ?? $response->basePrice ?? null),
            'styles' => array_map(fn ($s) => $this->parseStyle($s), $styles),
            'responseStatus' => $response->responseStatus?->responseCode ?? null,
        ];
    }

    /**
     * Parse style information.
     */
    protected function parseStyle(object $style): array
    {
        return [
            'id' => $style->id ?? null,
            'name' => $style->name ?? null,
            'year' => $style->modelYear ?? null,
            'division' => $style->division->_ ?? null,
            'subdivision' => $style->subdivision->_ ?? null,
            'model' => $style->model->_ ?? null,
            'trim' => $style->trim ?? null,
            'driveTrain' => $style->drivetrain ?? null,
            'passDoors' => $style->passDoors ?? null,
            'stockImage' => $style->stockImage?->url ?? null,
            'basePrice' => $this->parsePrice($style->basePrice ?? null),
        ];
    }

    /**
     * Parse color information.
     */
    protected function parseColors(array|object $colors): array
    {
        $colors = $this->toArray($colors);

        return array_map(fn ($c) => [
            'code' => $c->colorCode ?? null,
            'name' => $c->colorName ?? null,
            'rgb' => $c->rgbValue ?? null,
        ], $colors);
    }

    /**
     * Parse price information.
     */
    protected function parsePrice(?object $price): ?array
    {
        if ($price === null) {
            return null;
        }

        // Handle PriceRange (has nested Range objects)
        if (isset($price->msrp) && is_object($price->msrp)) {
            return [
                'msrp' => $price->msrp->low ?? $price->msrp->high ?? null,
                'invoice' => $price->invoice->low ?? $price->invoice->high ?? null,
                'destination' => $price->destination->low ?? $price->destination->high ?? null,
            ];
        }

        // Handle Price (has direct attributes)
        return [
            'msrp' => $price->msrp ?? null,
            'invoice' => $price->invoice ?? null,
            'destination' => $price->destination ?? null,
        ];
    }

    /**
     * Get engine displacement value.
     */
    protected function getDisplacement(object $engine): ?string
    {
        $displacement = $engine->displacement ?? null;
        if ($displacement === null) {
            return null;
        }

        $values = $this->toArray($displacement->value ?? []);

        return $values[0]?->_ ?? null;
    }

    /**
     * Parse identified strings (divisions, models, styles, etc.).
     */
    protected function parseIdentifiedStrings(array|object $items): array
    {
        $items = $this->toArray($items);

        return array_map(fn ($item) => [
            'id' => $item->id ?? null,
            'name' => $item->_ ?? $item->name ?? '',
        ], $items);
    }

    /**
     * Convert value to array.
     */
    protected function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return [$value];
        }

        return [];
    }

    /**
     * Generate cache key for VIN or style lookup.
     */
    protected function getCacheKey(string $identifier, bool $includeMediaGallery): string
    {
        $suffix = $includeMediaGallery ? ':gallery' : '';

        return $this->cachePrefix . $identifier . $suffix;
    }

    /**
     * Get data from Redis cache.
     */
    protected function getFromCache(string $key): ?array
    {
        $cached = Redis::get($key);

        return $cached ? json_decode($cached, true) : null;
    }

    /**
     * Save data to Redis cache.
     */
    protected function saveToCache(string $key, array $data): void
    {
        Redis::setex($key, $this->cacheTtl, json_encode($data));
    }

    /**
     * Get stock image URL by VIN with optional make/model/year for shared caching.
     * If make/model/year provided, checks their cache first to avoid duplicate API calls
     * for same vehicle spec across multiple VINs.
     */
    public function getStockImageByVin(
        string $vin,
        ?string $make = null,
        string|int|null $model = null,
        ?int $year = null,
        bool $skipCache = false
    ): ?string {
        // If make/model/year provided, check their shared cache first
        if ($make && $model && $year) {
            $mmyCacheKey = $this->stockImageCachePrefix . 'mmy:' . strtolower($make) . ':' . strtolower($model) . ':' . $year;

            if (! $skipCache) {
                $cached = Redis::get($mmyCacheKey);
                if ($cached !== null) {
                    return $cached === 'null' ? null : $cached;
                }
            }
        }

        // Use getVehicleInfoByVin which has its own caching
        $vehicleData = $this->getVehicleInfoByVin($vin, includeMediaGallery: false, skipCache: $skipCache);
        $stockImage = $vehicleData?->stockImage;

        // Also cache under make/model/year if provided (shared across same vehicles)
        if ($make && $model && $year && $stockImage) {
            $mmyCacheKey = $this->stockImageCachePrefix . 'mmy:' . strtolower($make) . ':' . strtolower($model) . ':' . $year;
            Redis::setex($mmyCacheKey, $this->cacheTtl, $stockImage ?? 'null');
        }

        return $stockImage;
    }

    /**
     * Clear cache for a specific VIN or style.
     */
    public function clearCache(string $identifier): void
    {
        Redis::del($this->cachePrefix . $identifier);
        Redis::del($this->cachePrefix . $identifier . ':gallery');
    }

    /**
     * Clear stock image cache.
     */
    public function clearStockImageCache(string $identifier): void
    {
        Redis::del($this->stockImageCachePrefix . $identifier);
    }
}
