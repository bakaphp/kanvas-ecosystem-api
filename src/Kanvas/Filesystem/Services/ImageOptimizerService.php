<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Illuminate\Support\Facades\Log;
use Spatie\ImageOptimizer\OptimizerChainFactory;

class ImageOptimizerService
{
    public static function optimizeImageFromUrl(string $imageUrl): string
    {
        $imagePath = FilesystemServices::downloadImageFromUrl($imageUrl);

        if (!$imagePath || !file_exists($imagePath)) {
            throw new \RuntimeException("Failed to download image from URL");
        }

        $maxRetries = 3;
        $retryDelay = 200000;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Log::info("Optimization attempt $attempt");

                $optimizerChain = OptimizerChainFactory::create();
                $optimizerChain
                    ->useLogger(Log::channel())
                    ->setTimeout(60)
                    ->optimize($imagePath);

                Log::info("Optimization succeeded on attempt $attempt");
                break; // Success, exit loop

            } catch (\Exception $e) {
                Log::warning("Optimization attempt $attempt failed", [
                    'error' => $e->getMessage(),
                    'path' => $imagePath,
                ]);

                if ($attempt === $maxRetries) {
                    Log::error('All optimization attempts failed, using unoptimized image');
                    // Don't throw, just return unoptimized image
                } else {
                    usleep($retryDelay);
                }
            }
        }

        return $imagePath;
    }
}
