<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\Actions;

use Closure;
use Illuminate\Support\Facades\Storage;
use Kanvas\Connectors\WordPress\AlgoliaClient;
use Kanvas\Connectors\WordPress\Client;
use Kanvas\Connectors\WordPress\WidgetClient;
use League\Csv\Writer;

class DownloadInventoryAction
{
    protected ?Closure $onPage;

    public function __construct(
        protected string $dealerName,
        protected string $baseUrl,
        protected string $apiPath,
        protected ?string $rooftopId = null,
        protected ?string $inventoryCatcherName = null,
        protected ?string $filterMake = null,
        protected string $provider = 'wp',
        protected ?string $algoliaAppId = null,
        protected ?string $algoliaApiKey = null,
        protected ?string $algoliaIndexName = null,
        protected ?string $widgetSiteId = null,
        protected ?string $widgetListingConfigId = null,
        ?Closure $onPage = null,
    ) {
        $this->onPage = $onPage;
    }

    public function execute(): array
    {
        $result = match ($this->provider) {
            'algolia' => $this->fetchFromAlgolia(),
            'widget' => $this->fetchFromWidget(),
            default => $this->fetchFromWordPress(),
        };

        $vehicles = $result['vehicles'];

        if (count($vehicles) === 0) {
            return [
                'success' => true,
                'dealer' => $this->dealerName,
                'total' => 0,
                'file_path' => null,
                'message' => 'No vehicles found',
            ];
        }

        /** @var list<array<string, string>> $rows */
        $rows = [];
        foreach ($vehicles as $vehicle) {
            $rows[] = match ($this->provider) {
                'algolia' => $this->mapAlgoliaVehicleToRow($vehicle),
                'widget' => $this->mapWidgetVehicleToRow($vehicle),
                default => $this->mapVehicleToRow($vehicle),
            };
        }

        $filePath = $this->writeCsv($rows);

        return [
            'success' => true,
            'dealer' => $this->dealerName,
            'total' => count($rows),
            'file_path' => $filePath,
            'message' => 'Downloaded ' . count($rows) . ' vehicles',
        ];
    }

    protected function fetchFromWordPress(): array
    {
        $client = new Client($this->baseUrl, $this->apiPath);

        return $client->getAllVehicles($this->filterMake, $this->onPage);
    }

    protected function fetchFromAlgolia(): array
    {
        $client = new AlgoliaClient(
            (string) $this->algoliaAppId,
            (string) $this->algoliaApiKey,
            (string) $this->algoliaIndexName,
        );

        return $client->getAllVehicles($this->onPage);
    }

    protected function fetchFromWidget(): array
    {
        $client = new WidgetClient(
            $this->baseUrl,
            (string) $this->widgetSiteId,
            $this->widgetListingConfigId ?? 'auto-new',
        );

        return $client->getAllVehicles($this->onPage);
    }

    protected function mapVehicleToRow(array $vehicle): array
    {
        $specs = $vehicle['specifications'] ?? [];
        if (is_array($specs) === false) {
            $specs = [];
        }

        $condition = $this->extractCondition($vehicle);
        $year = $this->extractYear($vehicle);
        $make = $this->extractMakeName($vehicle);
        $model = $this->extractModelName($vehicle);
        $stockNumber = (string) ($vehicle['stock_number'] ?? '');

        return [
            'rooftop_id' => $this->rooftopId ?? '',
            'name' => (string) ($vehicle['title'] ?? ''),
            'price' => (string) ($vehicle['price'] ?? 0),
            'condition' => $condition,
            'mileage' => (string) ($vehicle['mileage'] ?? 0),
            'stock' => $stockNumber,
            'vin' => (string) ($vehicle['vin'] ?? ''),
            'image_url' => $this->extractImages($vehicle),
            'reference_url' => (string) ($vehicle['link'] ?? ''),
            'year' => $year,
            'make' => $make,
            'model' => $model,
            'mpg_city' => (string) ($vehicle['mpg_city'] ?? ''),
            'mpg_hwy' => (string) ($vehicle['mpg_highway'] ?? ''),
            'engine' => (string) ($vehicle['engine'] ?? ''),
            'transmission' => (string) ($vehicle['transmission'] ?? ''),
            'exterior_color' => (string) ($vehicle['color'] ?? ''),
            'horsepower' => $this->getSpecValue($specs, 'mechanical', 'horse'),
            'torque' => $this->getSpecValue($specs, 'mechanical', 'torque'),
            'drivetrain' => $this->getSpecValue($specs, 'mechanical', 'drive')
                ?: $this->getSpecValue($specs, 'mechanical', 'tracción'),
            'cylinders' => $this->getSpecValue($specs, 'mechanical', 'cylinder'),
            'fuel_tank' => $this->getSpecValue($specs, 'mechanical', 'tank'),
            'body_style' => $this->getSpecValue($specs, 'general', 'body')
                ?: $this->getSpecValue($specs, 'general', 'cuerpo'),
            'description' => "{$condition} {$year} {$make} {$model} - {$stockNumber}",
        ];
    }

    protected function mapAlgoliaVehicleToRow(array $vehicle): array
    {
        $year = (string) ($vehicle['year'] ?? '');
        $make = (string) ($vehicle['make'] ?? '');
        $model = (string) ($vehicle['model'] ?? '');
        $condition = (string) ($vehicle['type'] ?? 'Used');
        $stockNumber = (string) ($vehicle['stock'] ?? '');
        $thumbnail = (string) ($vehicle['thumbnail'] ?? '');

        return [
            'rooftop_id' => $this->rooftopId ?? '',
            'name' => (string) ($vehicle['title_vrp'] ?? ''),
            'price' => (string) ($vehicle['our_price'] ?? $vehicle['msrp'] ?? 0),
            'condition' => $condition,
            'mileage' => (string) ($vehicle['miles'] ?? 0),
            'stock' => $stockNumber,
            'vin' => (string) ($vehicle['vin'] ?? ''),
            'image_url' => $thumbnail,
            'reference_url' => (string) ($vehicle['link'] ?? ''),
            'year' => $year,
            'make' => $make,
            'model' => $model,
            'mpg_city' => (string) ($vehicle['city_mpg'] ?? ''),
            'mpg_hwy' => (string) ($vehicle['hw_mpg'] ?? ''),
            'engine' => (string) ($vehicle['engine_description'] ?? ''),
            'transmission' => (string) ($vehicle['transmission_description'] ?? ''),
            'exterior_color' => (string) ($vehicle['ext_color'] ?? ''),
            'horsepower' => '',
            'torque' => '',
            'drivetrain' => (string) ($vehicle['drivetrain'] ?? ''),
            'cylinders' => (string) ($vehicle['cylinders'] ?? ''),
            'fuel_tank' => '',
            'body_style' => (string) ($vehicle['body'] ?? ''),
            'description' => "{$condition} {$year} {$make} {$model} - {$stockNumber}",
        ];
    }

    protected function mapWidgetVehicleToRow(array $vehicle): array
    {
        $year = (string) ($vehicle['year'] ?? '');
        $make = (string) ($vehicle['make'] ?? '');
        $model = (string) ($vehicle['model'] ?? '');
        $condition = ucfirst((string) ($vehicle['type'] ?? $vehicle['condition'] ?? 'Used'));
        $stockNumber = (string) ($vehicle['stockNumber'] ?? '');
        $vin = (string) ($vehicle['vin'] ?? '');
        $title = is_array($vehicle['title'] ?? null) ? (string) ($vehicle['title'][0] ?? '') : (string) ($vehicle['title'] ?? '');
        $link = (string) ($vehicle['link'] ?? '');
        if ($link !== '' && ! str_starts_with($link, 'http')) {
            $link = rtrim($this->baseUrl, '/') . '/' . ltrim($link, '/');
        }

        $tracking = $this->getWidgetTrackingMap($vehicle);
        $attrs = $this->getWidgetAttributeMap($vehicle);

        $image = '';
        $images = $vehicle['images'] ?? [];
        if (is_array($images) && isset($images[0]['uri'])) {
            $image = (string) $images[0]['uri'];
        }

        return [
            'rooftop_id' => $this->rooftopId ?? '',
            'name' => $title,
            'price' => $this->extractWidgetPrice($vehicle),
            'condition' => $condition,
            'mileage' => $tracking['odometer'] ?? '',
            'stock' => $stockNumber,
            'vin' => $vin,
            'image_url' => $image,
            'reference_url' => $link,
            'year' => $year,
            'make' => $make,
            'model' => $model,
            'mpg_city' => $tracking['cityFuelEconomy'] ?? $tracking['cityMpg'] ?? '',
            'mpg_hwy' => $tracking['highwayFuelEconomy'] ?? $tracking['highwayMpg'] ?? '',
            'engine' => $attrs['engine'] ?? trim((string) ($tracking['engineSize'] ?? '') . ' ' . (string) ($tracking['engine'] ?? '')),
            'transmission' => $attrs['transmission'] ?? $tracking['transmission'] ?? '',
            'exterior_color' => $attrs['exteriorColor'] ?? $tracking['exteriorColor'] ?? '',
            'horsepower' => '',
            'torque' => '',
            'drivetrain' => $tracking['driveLine'] ?? '',
            'cylinders' => '',
            'fuel_tank' => '',
            'body_style' => (string) ($vehicle['bodyStyle'] ?? ''),
            'description' => "{$condition} {$year} {$make} {$model} - {$stockNumber}",
        ];
    }

    protected function extractWidgetPrice(array $vehicle): string
    {
        $pricing = $vehicle['pricing'] ?? [];
        if (! is_array($pricing)) {
            return '';
        }

        $retailPrice = (string) ($pricing['retailPrice'] ?? '');
        if ($retailPrice !== '') {
            return (string) preg_replace('/[^0-9.]/', '', $retailPrice);
        }

        $dprice = $pricing['dprice'] ?? [];
        if (is_array($dprice)) {
            foreach ($dprice as $entry) {
                if (is_array($entry) && isset($entry['value'])) {
                    return (string) preg_replace('/[^0-9.]/', '', (string) $entry['value']);
                }
            }
        }

        return '';
    }

    protected function getWidgetTrackingMap(array $vehicle): array
    {
        $map = [];
        foreach ($vehicle['trackingAttributes'] ?? [] as $attr) {
            if (is_array($attr) && isset($attr['name'], $attr['value'])) {
                $map[(string) $attr['name']] = (string) $attr['value'];
            }
        }

        return $map;
    }

    protected function getWidgetAttributeMap(array $vehicle): array
    {
        $map = [];
        foreach ($vehicle['attributes'] ?? [] as $attr) {
            if (is_array($attr) && isset($attr['name'], $attr['value'])) {
                $map[(string) $attr['name']] = (string) $attr['value'];
            }
        }

        return $map;
    }

    protected function extractCondition(array $vehicle): string
    {
        $condition = $vehicle['condition'] ?? 'Used';

        if (is_array($condition)) {
            return (string) ($condition['name'] ?? 'Used');
        }

        return (string) $condition;
    }

    protected function extractYear(array $vehicle): string
    {
        $makeData = $vehicle['make'] ?? null;
        if (is_array($makeData)) {
            return (string) ($makeData['year'] ?? '');
        }

        return '';
    }

    protected function extractMakeName(array $vehicle): string
    {
        $makeData = $vehicle['make'] ?? null;
        if (! is_array($makeData)) {
            return '';
        }

        $make = $makeData['make'] ?? '';
        if (is_array($make)) {
            return (string) ($make['name'] ?? '');
        }

        return (string) $make;
    }

    protected function extractModelName(array $vehicle): string
    {
        $makeData = $vehicle['make'] ?? null;
        if (! is_array($makeData)) {
            return '';
        }

        $model = $makeData['model'] ?? '';
        if (is_array($model)) {
            return (string) ($model['name'] ?? '');
        }

        return (string) $model;
    }

    protected function extractImages(array $vehicle): string
    {
        $gallery = $vehicle['gallery'] ?? null;
        if (! is_array($gallery) || ! isset($gallery['data']) || ! is_array($gallery['data'])) {
            return '';
        }

        /** @var list<string> $imageUrls */
        $imageUrls = [];
        foreach ($gallery['data'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $marketing = $item['marketing'] ?? [];
            $url = '';

            if (is_array($marketing)) {
                $url = (string) ($marketing['1024x768']
                    ?? $marketing['1024x768-original_ratio']
                    ?? $marketing['640x480']
                    ?? '');
            }

            if ($url === '') {
                $url = (string) ($item['url'] ?? '');
            }

            if ($url !== '') {
                $imageUrls[] = $url;
            }
        }

        return implode('|', $imageUrls);
    }

    protected function getSpecValue(array $specs, string $category, string $keyword): string
    {
        $specList = $specs[$category] ?? [];

        if (is_string($specList)) {
            return stripos($specList, $keyword) !== false ? $specList : '';
        }

        if (! is_array($specList)) {
            return '';
        }

        // Handle single dict instead of list
        if (isset($specList['label'])) {
            $specList = [$specList];
        }

        foreach ($specList as $spec) {
            if (is_array($spec)) {
                $label = (string) ($spec['label'] ?? '');
                if (stripos($label, $keyword) !== false) {
                    return trim((string) ($spec['value'] ?? ''));
                }
            } elseif (is_string($spec) && stripos($spec, $keyword) !== false) {
                return $spec;
            }
        }

        return '';
    }

    protected function writeCsv(array $rows): string
    {
        $headers = [
            'rooftop_id', 'name', 'price', 'condition', 'mileage', 'stock', 'vin',
            'image_url', 'reference_url', 'year', 'make', 'model',
            'mpg_city', 'mpg_hwy', 'engine', 'transmission', 'exterior_color',
            'horsepower', 'torque', 'drivetrain', 'cylinders', 'fuel_tank',
            'body_style', 'description',
        ];

        $baseName = $this->inventoryCatcherName ?? $this->dealerName;
        $sanitizedName = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);
        $fileName = 'wordpress/' . $sanitizedName . '.csv';
        $filePath = Storage::disk('local')->path($fileName);

        $dir = dirname($filePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $csv = Writer::createFromPath($filePath, 'w+');
        $csv->insertOne($headers);
        $csv->insertAll($rows);

        return $filePath;
    }
}
