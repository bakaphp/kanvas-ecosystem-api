<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Throwable;

class CleanScrapperImageCommand extends Command
{
    protected $signature = 'kanvas:scrapper-cleanup-product-images {app_id} {company_id} {--product_id=} {--force : Force reprocessing of already cleaned images}';

    protected ?string $aiAPI = null;

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));

        $this->aiAPI = $app->get('scrapper_api_image_removal_api');
        $kanvasImageRemoval = $company->get('scrapper_api_image_removal_api_key') ?? 'https://cdn2.kanvas.dev/sc-mask.png';
        $productId = (int) $this->option('product_id');

        if ($productId) {
            $products = Products::fromApp($app)->fromCompany($company)->where('id', $productId)->where('is_published', 1)->get();
        }

        $products = Products::fromApp($app)->fromCompany($company)->get();

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

                // Get all files for this variant
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

                    // Remove watermark from the image
                    $cleanedImageUrl = $this->removeWatermarkFromImage($originalUrl, $kanvasImageRemoval);

                    if ($cleanedImageUrl) {
                        $cleanedFiles[] = [
                            'url' => $cleanedImageUrl,
                            'name' => $fileEntity->filesystem->name,
                        ];
                        $this->info('  ✓ Successfully cleaned image');
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

                    $processedCount++;
                    $this->info("  ✓ Variant {$variant->id} updated successfully");
                }
            }
        }

        $this->info("\n=== Processing Complete ===");
        $this->info("Variants processed: {$processedCount}");
        $this->info("Errors encountered: {$errorCount}");
    }

    /**
     * Remove watermark from image using AI API.
     *
     * @param string $imageUrl The URL of the image with watermark
     * @param string $maskUrl The URL of the mask image (white area = watermark to remove)
     * @return string|null The URL of the cleaned image, or null on failure
     */
    private function removeWatermarkFromImage(string $imageUrl, string $maskUrl): ?string
    {
        try {
            // Download images to temporary files
            $originalImage = Http::get($imageUrl)->body();
            $maskImage = Http::get($maskUrl)->body();

            if (empty($originalImage) || empty($maskImage)) {
                $this->warn('Failed to download images for watermark removal');

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
                $this->warn('API request failed: ' . $response->status());

                return null;
            }

            $result = $response->json();

            // Return the cleaned image URL from the response
            // The API should return the URL of the cleaned image
            if (isset($result['url'])) {
                return $result['url'];
            }

            if (isset($result['image_url'])) {
                return $result['image_url'];
            }

            if (isset($result['data']['url'])) {
                return $result['data']['url'];
            }

            // If the response contains base64 or other formats, handle accordingly
            $this->warn('Unexpected API response format');

            return null;
        } catch (Throwable $e) {
            $this->error('Error removing watermark: ' . $e->getMessage());

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

                return $this->removeWatermarkFromImage($imageUrl, $maskUrl);
            });

            // Use cleaned image if available, otherwise use original
            $processedImages[] = $cleanedImageUrl ?? $imageUrl;
        }

        return $processedImages;
    }
}
