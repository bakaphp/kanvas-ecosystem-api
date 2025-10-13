<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Users\Models\Users;
use Throwable;

class CleanScrapperImageCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:scrapper-cleanup-product-images {app_id} {company_id} {--product_id=} {--force : Force reprocessing of already cleaned images} {--provider=pixelbin : Watermark removal provider (pixelbin or legacy)}';

    protected ?string $aiAPI = null;
    protected ?string $pixelbinApiToken = null;
    protected string $provider = 'pixelbin';
    protected Apps $app;
    protected Users $user;

    public function handle(): void
    {
        $this->app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($this->app);
        $company = Companies::getById((int) $this->argument('company_id'));
        $this->user = $company->users()->first();

        // Determine which provider to use
        $requestedProvider = strtolower($this->option('provider') ?? 'pixelbin');

        // Try PixelBin first (default)
        if ($requestedProvider === 'pixelbin') {
            $this->pixelbinApiToken = $this->app->get('pixelbin_api_token');

            if (! empty($this->pixelbinApiToken)) {
                $this->provider = 'pixelbin';
                $this->info('Using PixelBin watermark removal provider');
            } else {
                $this->warn('PixelBin API token not configured, falling back to legacy provider...');
                $requestedProvider = 'legacy';
            }
        }

        // Setup legacy provider if needed
        if ($requestedProvider === 'legacy') {
            $this->aiAPI = $this->app->get('scrapper_api_image_removal_api');

            if (! empty($this->aiAPI)) {
                $this->provider = 'legacy';
                $this->info('Using legacy watermark removal provider');
            } else {
                $this->error('No watermark removal provider configured. Please configure either pixelbin_api_token or scrapper_api_image_removal_api');

                return;
            }
        }

        $kanvasImageRemoval = $company->get('scrapper_api_image_removal_api_key') ?? 'https://cdn2.kanvas.dev/sc-mask.png';
        $productId = (int) $this->option('product_id');

        if ($productId) {
            $products = Products::fromApp($this->app)->fromCompany($company)->where('id', $productId)->where('is_published', 1)->get();
        } else {
            $products = Products::fromApp($this->app)->fromCompany($company)->where('is_published', 1)->get();
        }

        $this->info('Processing ' . $products->count() . ' products...');
        $processedCount = 0;
        $errorCount = 0;

        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $this->info("Processing variant ID: {$variant->id} - SKU: {$variant->sku}");

                // Check if this variant has already been processed
                if ($variant->get('watermark_removed') && ! $this->option('force')) {
                    $this->info("  ⊘ Skipping - watermark already removed for variant {$variant->id}");

                    continue;
                }

                $variant->clearLightHouseCache(withKanvasConfiguration: false);
                $files = $variant->getFiles();

                if ($files->isEmpty()) {
                    $this->warn("  No files found for variant {$variant->id}");

                    continue;
                }

                $this->info("  Found {$files->count()} file(s) to process");
                $cleanedFiles = [];

                foreach ($files as $fileEntity) {
                    $originalUrl = $fileEntity->filesystem->url;
                    $this->info("  Processing file: {$originalUrl}");

                    // Remove watermark from the image using selected provider
                    $cleanedImageUrl = $this->provider === 'pixelbin'
                        ? $this->removeWatermarkWithPixelBin($originalUrl)
                        : $this->removeWatermarkWithLegacy($originalUrl, $kanvasImageRemoval);

                    sleep($this->provider === 'pixelbin' ? 2 : 1);

                    if ($cleanedImageUrl) {
                        // Upload cleaned image to app's CDN
                        $cdnUrl = $this->uploadToCdn($cleanedImageUrl, $fileEntity->filesystem->name);

                        if ($cdnUrl) {
                            $cleanedFiles[] = [
                                'url' => $cdnUrl,
                                'name' => $fileEntity->filesystem->name,
                            ];
                            $this->info('  ✓ Successfully cleaned and uploaded image');
                        } else {
                            $this->warn('  ✗ Failed to upload cleaned image, will keep original');
                            $cleanedFiles[] = [
                                'url' => $originalUrl,
                                'name' => $fileEntity->filesystem->name,
                            ];
                            $errorCount++;
                        }
                    } else {
                        $this->warn('  ✗ Failed to clean image, will keep original');
                        $cleanedFiles[] = [
                            'url' => $originalUrl,
                            'name' => $fileEntity->filesystem->name,
                        ];
                        $errorCount++;
                    }
                }

                // Delete old files from the variant
                if (! empty($cleanedFiles)) {
                    $this->info('  Removing old files and attaching cleaned files...');
                    $variant->deleteFiles();

                    // Add new cleaned files
                    foreach ($cleanedFiles as $file) {
                        $variant->addFileFromUrl($file['url'], $file['name']);
                    }

                    // Mark variant as processed to avoid reprocessing
                    $variant->set('watermark_removed', true);
                    $variant->set('watermark_removed_at', now()->toDateTimeString());
                    $variant->set('watermark_removed_provider', $this->provider);

                    $processedCount++;
                    $this->info("  ✓ Variant {$variant->id} updated successfully");
                }
            }
        }

        $this->info("\n=== Processing Complete ===");
        $this->info("Provider used: {$this->provider}");
        $this->info("Variants processed: {$processedCount}");
        $this->info("Errors encountered: {$errorCount}");
    }

    /**
     * Remove watermark from image using PixelBin API.
     *
     * @param string $imageUrl The URL of the image with watermark
     * @return string|null The URL of the cleaned image, or null on failure
     */
    private function removeWatermarkWithPixelBin(string $imageUrl): ?string
    {
        try {
            $this->info('  Submitting to PixelBin API...');

            // Submit the watermark removal request
            $response = Http::timeout(120)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . base64_encode($this->pixelbinApiToken),
                ])
                ->asMultipart()
                ->post('https://api.pixelbin.io/service/platform/transformation/v1.0/predictions/wm/remove', [
                    [
                        'name' => 'input.image',
                        'contents' => $imageUrl,
                    ],
                    [
                        'name' => 'input.rem_text',
                        'contents' => 'true',
                    ],
                    [
                        'name' => 'input.rem_logo',
                        'contents' => 'true',
                    ],
                    [
                        'name' => 'input.box1',
                        'contents' => '0_0_100_100',
                    ],
                    [
                        'name' => 'input.box2',
                        'contents' => '0_0_0_0',
                    ],
                    [
                        'name' => 'input.box3',
                        'contents' => '0_0_0_0',
                    ],
                    [
                        'name' => 'input.box4',
                        'contents' => '0_0_0_0',
                    ],
                    [
                        'name' => 'input.box5',
                        'contents' => '0_0_0_0',
                    ],
                ]);

            if (! $response->successful()) {
                $this->warn('  API request failed: ' . $response->status());
                $this->warn('  Response: ' . $response->body());

                return null;
            }

            $result = $response->json();

            // Extract the status URL
            $statusUrl = $result['urls']['get'] ?? null;

            if (! $statusUrl) {
                $this->warn('  No status URL in response');

                return null;
            }

            $this->info('  Polling for result...');

            // Poll for the result
            return $this->pollForResult($statusUrl);
        } catch (Throwable $e) {
            $this->error('  Error removing watermark: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Poll the PixelBin API for the result.
     *
     * @param string $statusUrl The URL to poll for status
     * @param int $maxAttempts Maximum number of polling attempts
     * @param int $sleepSeconds Seconds to wait between polls
     * @return string|null The URL of the cleaned image, or null on failure
     */
    private function pollForResult(string $statusUrl, int $maxAttempts = 30, int $sleepSeconds = 3): ?string
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::timeout(30)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . base64_encode($this->pixelbinApiToken),
                    ])
                    ->get($statusUrl);

                if (! $response->successful()) {
                    $this->warn("  Poll attempt {$attempt}/{$maxAttempts} failed: " . $response->status());
                    sleep($sleepSeconds);

                    continue;
                }

                $result = $response->json();
                $status = $result['status'] ?? null;

                if ($status === 'SUCCESS') {
                    $outputUrl = $result['output'][0] ?? null;

                    if ($outputUrl) {
                        $this->info('  ✓ Watermark removal completed');

                        return $outputUrl;
                    }

                    $this->warn('  Status is SUCCESS but no output URL found');

                    return null;
                }

                if ($status === 'FAILURE') {
                    $this->warn('  Watermark removal failed');

                    return null;
                }

                // Status is PENDING, continue polling
                $this->info("  Poll attempt {$attempt}/{$maxAttempts} - Status: {$status}");
                sleep($sleepSeconds);
            } catch (Throwable $e) {
                $this->warn("  Poll attempt {$attempt}/{$maxAttempts} error: " . $e->getMessage());
                sleep($sleepSeconds);
            }
        }

        $this->warn('  Max polling attempts reached, watermark removal timed out');

        return null;
    }

    /**
     * Remove watermark from image using legacy AI API with mask.
     *
     * @param string $imageUrl The URL of the image with watermark
     * @param string $maskUrl The URL of the mask image (white area = watermark to remove)
     * @return string|null The URL of the cleaned image, or null on failure
     */
    private function removeWatermarkWithLegacy(string $imageUrl, string $maskUrl): ?string
    {
        try {
            // Download images to temporary files
            $originalImage = Http::get($imageUrl)->body();
            $maskImage = Http::get($maskUrl)->body();

            if (empty($originalImage) || empty($maskImage)) {
                $this->warn('  Failed to download images for watermark removal');

                return null;
            }

            // Create temporary files
            $tempOriginal = tempnam(sys_get_temp_dir(), 'original_');
            $tempMask = tempnam(sys_get_temp_dir(), 'mask_');

            file_put_contents($tempOriginal, $originalImage);
            file_put_contents($tempMask, $maskImage);

            // Make API request
            $response = Http::timeout(120)
                ->attach('image', file_get_contents($tempOriginal), 'original.jpg')
                ->attach('image_2', file_get_contents($tempMask), 'mask.png')
                ->post($this->aiAPI, [
                    'model' => 'gemini-2.5-flash-image-preview',
                    'prompt' => "I'm providing you with two images. The first is the original image, and the second is a mask. I want you to remove the element that corresponds to the white area in the mask image from the original image. After removing it, fill the empty space so that it matches the surrounding background realistically and consistently. The final result should be the original image without the object that was highlighted by the mask.",
                ]);

            // Clean up temporary files
            unlink($tempOriginal);
            unlink($tempMask);

            if (! $response->successful()) {
                $this->warn('  API request failed: ' . $response->status());

                return null;
            }

            $result = $response->json();

            // Return the cleaned image URL from the response
            if (isset($result[0]['url'])) {
                return $result[0]['url'];
            }

            if (isset($result[0]['image_url'])) {
                return $result[0]['image_url'];
            }

            if (isset($result['data']['url'])) {
                return $result['data']['url'];
            }

            $this->warn('  Unexpected API response format');

            return null;
        } catch (Throwable $e) {
            $this->error('  Error removing watermark: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Upload cleaned image from URL to app's CDN.
     *
     * @param string $imageUrl The URL of the cleaned image to upload
     * @param string $originalName The original filename
     * @return string|null The CDN URL of the uploaded file, or null on failure
     */
    private function uploadToCdn(string $imageUrl, string $originalName): ?string
    {
        try {
            $filesystemService = new FilesystemServices($this->app);
            $filesystem = $filesystemService->uploadFileFromUrl($imageUrl, $this->user);

            return $filesystem->url;
        } catch (Throwable $e) {
            $this->error('  Error uploading to CDN: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Process images and remove watermarks if mask is available.
     *
     * @param array $images Array of image URLs
     * @param string|null $maskUrl Optional mask URL for watermark removal
     * @return array Processed image URLs
     */
    private function processImagesWithWatermarkRemoval(array $images, ?string $maskUrl = null): array
    {
        if (empty($maskUrl)) {
            return $images;
        }

        $processedImages = [];
        $cacheMinutes = 60 * 24 * 7; // Cache for 7 days

        foreach ($images as $imageUrl) {
            // Create cache key for cleaned images
            $cleanedImageCacheKey = 'cleaned_image_' . md5($imageUrl . $maskUrl);

            // Try to get cached cleaned image first
            $cleanedImageUrl = Cache::remember($cleanedImageCacheKey, $cacheMinutes, function () use ($imageUrl, $maskUrl) {
                $this->info('Removing watermark from: ' . $imageUrl);

                return $this->removeWatermarkWithLegacy($imageUrl, $maskUrl);
            });

            // Use cleaned image if available, otherwise use original
            $processedImages[] = $cleanedImageUrl ?? $imageUrl;
        }

        return $processedImages;
    }
}
