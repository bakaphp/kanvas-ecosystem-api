<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Illuminate\Support\Facades\Log;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\Optimizers\Jpegoptim;
use Spatie\ImageOptimizer\Optimizers\Optipng;

class ImageOptimizerService
{
    public static function optimizeImageFromUrl(string $imageUrl): string
    {
        chdir(storage_path('app/temp'));
        $imagePath = FilesystemServices::downloadImageFromUrl($imageUrl);

        if (! $imagePath || ! file_exists($imagePath)) {
            throw new \RuntimeException("Failed to download image from URL");
        }

        $maxRetries = 3;
        $retryDelay = 200000;

        Log::info("Trying url $imageUrl");
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $optimizerChain = new OptimizerChain();
                $optimizerChain->addOptimizer(
                    new Optipng([
                        '-i0',
                        '-o2',
                        '-quiet',
                    ])
                );

                $optimizerChain->addOptimizer(
                    new Jpegoptim([
                        '-m85',
                        '--strip-all',
                        '--all-progressive',
                    ])
                );

                $optimizerChain
                    ->useLogger(Log::channel())
                    ->setTimeout(60)
                    ->optimize($imagePath);
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
