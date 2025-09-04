<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Baka\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Throwable;

class ScrapperProductInventoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:scrapper-product-inventory 
                            {app_id : The application ID} 
                            {userId : The user ID} 
                            {company_id : The company ID} 
                            {region_id : The region ID}
                            {api_key : The Scraping Dog API key}
                            {base_url : The base URL to scrape (e.g., https://www..com)}
                            {dealer_path : The dealer path (e.g., dealers/)}
                            {--pages=1 : Number of pages to scrape (default: 1)}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync all products inventory from Scrapper API';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $regions = Regions::getById((int) $this->argument('region_id'));
        $user = Users::getById((int) $this->argument('userId'));

        // Get the new parameters
        $apiKey = $this->argument('api_key');
        $baseUrl = rtrim($this->argument('base_url'), '/'); // Remove trailing slash
        $dealerPath = ltrim($this->argument('dealer_path'), '/'); // Remove leading slash
        $numberOfPages = (int) $this->option('pages');

        $cacheMinutes = 60 * 24; // Cache for 24 hours
        $allResults = [];

        // Loop through the specified number of pages
        for ($pageSkip = 0; $pageSkip < $numberOfPages; $pageSkip++) {
            $this->info('Processing page ' . ($pageSkip + 1) . ' of ' . $numberOfPages . '...');

            $cacheKey = 'scrapper_api_' . md5($baseUrl . '_' . $dealerPath) . '_pageskip_' . $pageSkip;

            // Try to get cached results first
            $pageResults = Cache::remember($cacheKey, $cacheMinutes, function () use ($pageSkip, $apiKey, $baseUrl, $dealerPath) {
                $response = Http::get('https://api.scrapingdog.com/scrape', [
                    'api_key' => $apiKey,
                    'url' => $baseUrl . '/' . $dealerPath . '/?PagingPageSkip=' . $pageSkip,
                    'dynamic' => 'false',
                    'ai_query' => 'Extract all the vehicle information you can + the detail link',
                ]);

                $responseBody = $response->body();

                if (Str::isJson($responseBody)) {
                    return json_decode($responseBody, true);
                }

                return null;
            });

            if ($pageResults === null) {
                $this->warn('No products extracted for page ' . ($pageSkip + 1) . ', skipping...');

                continue;
            }

            // Merge results from this page
            $allResults = array_merge($allResults, $pageResults);
            $this->info('Found ' . count($pageResults) . ' products on page ' . ($pageSkip + 1));
        }

        if (empty($allResults)) {
            $this->error('No products extracted from any page with scrapper api');

            return;
        }

        $results = $allResults;

        $importedProducts = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($results as $resultDetail) {
            $product = [
                'name' => $resultDetail['brand'] . ' ' . $resultDetail['model'] . ' ' . $resultDetail['year'],
                'sku' => $resultDetail['detail_link'],
                'price' => $this->cleanPrice($resultDetail['price'] ?? null),
            ];

            // Extract the detail link and create a cache key
            $detailLink = $resultDetail['detail_link'] ?? '';
            if (empty($detailLink)) {
                $this->warn('No detail link found for product: ' . $product['name']);

                continue;
            }

            // Create cache key based on the detail link
            $detailCacheKey = 'scrapper_api_product_details_' . md5($detailLink);

            // Try to get cached product details first
            $productDetails = Cache::remember($detailCacheKey, $cacheMinutes, function () use ($detailLink, $apiKey, $baseUrl) {
                $detailResponse = Http::get('https://api.scrapingdog.com/scrape', [
                    'api_key' => $apiKey,
                    'url' => $baseUrl . '/' . $detailLink,
                    'dynamic' => 'false',
                    'ai_query' => 'Extract as much vehicle information needed to populate a pdp',
                ]);

                $detailResponseBody = $detailResponse->body();

                if (Str::isJson($detailResponseBody)) {
                    return json_decode($detailResponseBody, true);
                }

                return null;
            });

            if ($productDetails === null) {
                $this->warn('Could not extract details for product: ' . $product['name']);

                continue;
            }

            // Create cache key for images based on the detail link
            $imagesCacheKey = 'scrapper_api_product_images_' . md5($detailLink);

            // Try to get cached product images first
            $productImages = Cache::remember($imagesCacheKey, $cacheMinutes, function () use ($detailLink, $apiKey, $baseUrl) {
                $imagesResponse = Http::get('https://api.scrapingdog.com/scrape', [
                    'api_key' => $apiKey,
                    'url' => $baseUrl . '/' . $detailLink,
                    'dynamic' => 'false',
                    'ai_query' => 'Extract all the vehicle images',
                ]);

                $imagesResponseBody = $imagesResponse->body();

                if (Str::isJson($imagesResponseBody)) {
                    return json_decode($imagesResponseBody, true);
                }

                return null;
            });

            if ($productImages === null) {
                $this->warn('Could not extract images for product: ' . $product['name']);
                // Continue processing even if images fail
            }

            // Merge the basic product info with the detailed info
            $completeProduct = array_merge($product, $productDetails);

            // Clean all price fields in the complete product
            if (is_array($completeProduct['price'])) {
                continue;
            }
            
            if (isset($completeProduct['price'])) {
                $completeProduct['price'] = $this->cleanPrice($completeProduct['price']);
            }

            // Clean price in vehicle details if exists
            if (isset($completeProduct['vehicle']['price'])) {
                $completeProduct['vehicle']['price'] = $this->cleanPrice($completeProduct['vehicle']['price']);
            }

            // Add images to the complete product if available
            if ($productImages !== null) {
                // Process images to get full-size versions
                $fullSizeImages = [];
                foreach ($productImages as $image) {
                    if (is_string($image)) {
                        // Replace thumbnail dimensions with full-size dimensions
                        $fullSizeImage = str_replace(['188x125/5/', '266x600/0/'], '1024x768/0/', $image);
                        $fullSizeImages[] = $fullSizeImage;
                    } elseif (is_array($image)) {
                        // If the image data is in a nested array, process recursively
                        foreach ($image as $img) {
                            if (is_string($img)) {
                                $fullSizeImage = str_replace(['188x125/5/', '266x600/0/'], '1024x768/0/', $img);
                                $fullSizeImages[] = $fullSizeImage;
                            }
                        }
                    }
                }
                $completeProduct['images'] = $fullSizeImages;
            }

            // Remove dealer information if present
            if (isset($completeProduct['dealer'])) {
                unset($completeProduct['dealer']);
            }

            // Transform the vehicle data into the required structure
            $mappedProductData = $this->mapToProductStructure($completeProduct, $company, $regions);

            try {
                // Import the product using ProductImporterAction
                $importedProduct = (
                    new ProductImporterAction(
                        ProductImporter::from($mappedProductData),
                        $company,
                        $user,
                        $regions,
                        $app,
                        true
                    )
                )->execute();

                // Make the product searchable
                $importedProduct->searchable();

                $importedProducts[] = $importedProduct;
                $successCount++;

                $this->info('Successfully imported product: ' . $mappedProductData['name'] . ' (SKU: ' . $mappedProductData['sku'] . ')');
            } catch (Throwable $e) {
                $this->error('Failed to import product: ' . $mappedProductData['name'] . ' - Error: ' . $e->getMessage());
                $failedCount++;

                continue;
            }
        }

        $this->info('');
        $this->info('=== Import Summary ===');
        $this->info('Total products processed: ' . count($results));
        $this->info('Successfully imported: ' . $successCount);
        $this->info('Failed imports: ' . $failedCount);
        $this->info('===================');
    }

    private function cleanPrice(int|null|string $price): ?string
    {
        if ($price === null) {
            return null;
        }

        // Remove US$, USD, $, spaces, and commas
        return trim(str_replace(['US$', 'USD', '$', ',', ' '], '', $price));
    }

    private function extractIdFromDetailLink(?string $detailLink): string
    {
        if (empty($detailLink)) {
            return '';
        }

        // Remove trailing slash if present
        $detailLink = rtrim($detailLink, '/');

        // Extract the last part after the last slash
        $parts = explode('/', $detailLink);
        $id = end($parts);

        // Return only if it's numeric, otherwise return empty string
        return is_numeric($id) ? $id : '';
    }

    /**
     * Map vehicle data to the required structure.
     */
    private function mapToProductStructure(array $vehicleData, Companies $company, Regions $region): array
    {
        // Get default warehouse and channel for the company
        $warehouse = $region->defaultWarehouse;
        $channels = Channels::getDefault($company);

        $price = (float) ($vehicleData['price'] ?? 0);

        // Extract ID from the detail link/sku
        $detailLink = $vehicleData['sku'] ?? '';
        $extractedId = $this->extractIdFromDetailLink($detailLink);
        $sku = $extractedId ?: ($vehicleData['anuncio_number'] ?? '');

        $name = $vehicleData['name'] ?? '';

        // Extract description
        $observations = $vehicleData['observations'] ?? '';

        if (is_array($observations)) {
            $observations = implode("\n", $observations);
        }

        // Map images to files structure
        $files = [];
        if (isset($vehicleData['images']) && is_array($vehicleData['images'])) {
            $imageIndex = 0;
            foreach ($vehicleData['images'] as $imageUrl) {
                $imageIndex++;
                $files[] = [
                    'url' => $imageUrl,
                    'name' => 'image_' . $imageIndex,
                    'field_name' => 'product_image',
                ];
            }
        }

        $mappedProduct = [
            'name' => $name,
            'description' => $observations,
            'price' => $price,
            'discountPrice' => null,
            'slug' => Str::slug($name . '-' . $sku),
            'sku' => $sku,
            'source' => 'website',
            'source_id' => $sku,
            'files' => $files,
            'quantity' => 1,
            'isPublished' => true,
            'categories' => [],
            'warehouses' => [
                [
                    'id' => $warehouse?->id ?? 0,
                    'price' => $price,
                    'warehouse' => $warehouse?->name ?? 'Default',
                    'quantity' => 1,
                    'sku' => $sku,
                    'is_new' => true,
                    'channel' => $channels?->name ?? 'Default',
                ],
            ],
            'attributes' => $this->getVehicleAttributes($vehicleData),
            'custom_fields' => [],
        ];

        // Add variant structure - vehicles typically don't have variants, so we create a single variant
        $variant = [
            'name' => $name,
            'description' => $observations,
            'slug' => Str::slug($sku),
            'sku' => $sku,
            'price' => $price,
            'discountPrice' => null,
            'quantity' => 1,
            'attributes' => [],
            'files' => $files,
            'warehouses' => [
                [
                    'id' => $warehouse?->id ?? 0,
                    'price' => $price,
                    'quantity' => 1,
                    'sku' => $sku,
                    'is_new' => true,
                ],
            ],
            'channels' => [
                [
                    'price' => $price,
                    'discounted_price' => null,
                    'is_published' => true,
                    'warehouses_id' => $warehouse?->id ?? 0,
                    'channels_id' => $channels?->id ?? 0,
                ],
            ],
        ];

        $mappedProduct['variants'] = [$variant];

        return $mappedProduct;
    }

    /**
     * Get vehicle attributes in standardized format.
     */
    private function getVehicleAttributes(array $vehicleData): array
    {
        $attributes = [
            [
                'name' => 'year',
                'value' => $vehicleData['year'] ?? '',
            ],
            [
                'name' => 'make',
                'value' => $vehicleData['make'] ?? '',
            ],
            [
                'name' => 'model',
                'value' => $vehicleData['model'] ?? '',
            ],
            [
                'name' => 'new',
                'value' => 1, // We don't have condition info
            ],
            [
                'name' => 'body',
                'value' => $vehicleData['body_type'] ?? '',
            ],
            [
                'name' => 'transmission',
                'value' => $vehicleData['transmission'] ?? '',
            ],
            [
                'name' => 'trim',
                'value' => '', // We don't have trim info
            ],
            [
                'name' => 'exterior_color',
                'value' => $vehicleData['color'] ?? '',
            ],
            [
                'name' => 'interior_color',
                'value' => $vehicleData['interior_color'] ?? '',
            ],
            [
                'name' => 'engine',
                'value' => isset($vehicleData['engine']) ? json_encode($vehicleData['engine']) : '',
            ],
            [
                'name' => 'engine_type',
                'value' => '', // We don't have specific engine type
            ],
            [
                'name' => 'fuel_type',
                'value' => $vehicleData['fuel_type'] ?? '',
            ],
            [
                'name' => 'door_count',
                'value' => $vehicleData['number_of_doors'] ?? '',
            ],
            [
                'name' => 'date_in_stock',
                'value' => '', // We don't have this info
            ],
        ];

        // Remove attributes with empty values
        $filteredAttributes = [];
        foreach ($attributes as $attribute) {
            $value = (string) $attribute['value'];
            if (! empty(trim($value))) {
                $filteredAttributes[] = $attribute;
            }
        }

        return $filteredAttributes;
    }
}
