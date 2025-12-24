<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;
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
         * 1) OPTIONAL RESIZE (Intervention)
         * ----------------------------
         */
        if (($maxWidth !== null || $maxHeight !== null)
            && in_array($extension, $resizableExtensions, true)) {
            try {
                $img = Image::make($imagePath);

                $img->resize(
                    $maxWidth,
                    $maxHeight,
                    function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    }
                );

                // Format-aware saving
                switch ($extension) {
                    case 'jpg':
                    case 'jpeg':
                        $img->save($imagePath, 90);

                        break;
                    case 'png':
                        $img->save($imagePath, 9); // PNG compression

                        break;
                    case 'webp':
                        $img->encode('webp', 90)->save($imagePath);

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
         * 3) TARGET FILE SIZE (JPEG + WEBP, PNG cannot be reduced)
         * -----------------------------------------------------------
         */
        if ($targetSizeMb !== null) {
            if (self::isJpeg($extension) || self::isWebp($extension)) {
                $targetBytes = $targetSizeMb * 1024 * 1024;

                $quality = 85;
                $minQuality = 20;

                while (filesize($imagePath) > $targetBytes && $quality >= $minQuality) {
                    try {
                        $img = Image::make($imagePath);

                        if (self::isJpeg($extension)) {
                            $img->save($imagePath, $quality);
                        } elseif (self::isWebp($extension)) {
                            $img->encode('webp', $quality)->save($imagePath);
                        }
                    } catch (Exception $e) {
                        report($e);

                        break;
                    }

                    $quality -= 5;
                }
            }
        }

        return $imagePath;
    }

    private static function isJpeg(string $ext): bool
    {
        return in_array(strtolower($ext), ['jpg', 'jpeg'], true);
    }

    private static function isWebp(string $ext): bool
    {
        return strtolower($ext) === 'webp';
    }
}
