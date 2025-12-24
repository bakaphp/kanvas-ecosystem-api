<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\Optimizers\Jpegoptim;
use Spatie\ImageOptimizer\Optimizers\Optipng;

class ImageOptimizerService
{
    public static function optimizeImageFromUrl(
        string $imageUrl,
        ?int $targetSizeMb = null,
        ?int $maxWidth = null,
        ?int $maxHeight = null
    ): string {
        $tempPath = storage_path('app/temp');
        if (! is_dir($tempPath)) {
            if (! mkdir($tempPath, 0755, true) && ! is_dir($tempPath)) {
                throw new RuntimeException("Failed to create temp directory at: $tempPath");
            }
        }

        if (! chdir($tempPath)) {
            throw new RuntimeException("Failed to change directory to: $tempPath");
        }

        $imagePath = FilesystemServices::downloadImageFromUrl($imageUrl);
        if ($imagePath === null || ! file_exists($imagePath)) {
            throw new RuntimeException('Failed to download image from URL');
        }

        $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        $resizableExtensions = ['jpg', 'jpeg', 'png', 'webp'];

        /**
         * ----------------------------
         * 1) RESIZE (Intervention v3)
         * ----------------------------
         */
        if (($maxWidth !== null || $maxHeight !== null)
            && in_array($extension, $resizableExtensions, true)) {
            try {
                $manager = self::manager();
                $img = $manager->read($imagePath);

                // scale() keeps aspect ratio automatically in v3
                $img = $img->scale($maxWidth, $maxHeight);

                // Format-aware save
                switch ($extension) {
                    case 'jpg':
                    case 'jpeg':
                        $img->save($imagePath, quality: 90);

                        break;
                    case 'png':
                        $img->toPng()->save($imagePath);

                        break;
                    case 'webp':
                        $img->toWebp(90)->save($imagePath);

                        break;
                }
            } catch (Exception $e) {
                report($e);
            }
        }

        /**
         * ----------------------------
         * 2) SPATIE OPTIMIZATION
         * ----------------------------
         */
        try {
            $optimizerChain = new OptimizerChain();

            $optimizerChain->addOptimizer(new Optipng(['-i0', '-o2', '-quiet']));
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
        } catch (Exception $e) {
            report($e);
        }

        /**
         * -----------------------------------------------------------
         * 3) TARGET SIZE REDUCTION (JPEG + WEBP ONLY)
         * -----------------------------------------------------------
         */
        if ($targetSizeMb !== null && (self::isJpeg($extension) || self::isWebp($extension))) {
            $targetBytes = $targetSizeMb * 1024 * 1024;
            $quality = 85;
            $minQuality = 20;

            while (filesize($imagePath) > $targetBytes && $quality >= $minQuality) {
                try {
                    $manager = self::manager();
                    $img = $manager->read($imagePath);

                    if (self::isJpeg($extension)) {
                        $img->save($imagePath, quality: $quality);
                    } elseif (self::isWebp($extension)) {
                        $img->toWebp($quality)->save($imagePath);
                    }
                } catch (Exception $e) {
                    report($e);

                    break;
                }

                $quality -= 5;
            }
        }

        return $imagePath;
    }

    private static function isJpeg(string $ext): bool
    {
        return in_array($ext, ['jpg', 'jpeg'], true);
    }

    private static function isWebp(string $ext): bool
    {
        return $ext === 'webp';
    }

    protected static function manager(): ImageManager
    {
        return new ImageManager(new Driver());
    }
}
