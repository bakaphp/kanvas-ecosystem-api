<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Illuminate\Support\Facades\Log;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\Optimizers\Jpegoptim;
use Spatie\ImageOptimizer\Optimizers\Optipng;
use Spatie\ImageOptimizer\Optimizers\Pngquant;


class ImageOptimizerService
{
    public static function optimizeImageFromUrl(string $imageUrl): string
    {
        $imagePath = FilesystemServices::downloadImageFromUrl($imageUrl);

        if (! $imagePath || ! file_exists($imagePath)) {
            throw new \RuntimeException("Failed to download image from URL");
        }

        try {
            // Manually create the optimizer chain, bypassing the factory
            $optimizerChain = new OptimizerChain();

            // Add optimizers directly with their config
            $optimizerChain->addOptimizer(
                new Jpegoptim([
                    '-m85',
                    '--strip-all',
                    '--all-progressive',
                ])
            );

            $optimizerChain->addOptimizer(
                new Pngquant([
                    '--force',
                ])
            );

            $optimizerChain->addOptimizer(
                new Optipng([
                    '-i0',
                    '-o2',
                    '-quiet',
                ])
            );

            $optimizerChain->setTimeout(60);

            // Optimize the image
            $optimizerChain->optimize($imagePath);
        } catch (\Exception $e) {
            Log::error('Image optimization failed', [
                'error' => $e->getMessage(),
                'path' => $imagePath,
            ]);
            // Return the unoptimized image path
        }

        return $imagePath;
    }
}
