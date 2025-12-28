<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Baka\Support\Str;
use Exception;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\ImageManager;
use Kanvas\Filesystem\Models\Filesystem;
use RuntimeException;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\Optimizers\Jpegoptim;
use Spatie\ImageOptimizer\Optimizers\Optipng;

class ImageOptimizerService
{
    /**
    * Optimize an existing Filesystem entity and update it with the optimized image.
    *
    * Downloads the image from the filesystem URL, optimizes it, re-uploads to cloud storage,
    * and updates the Filesystem record with the new URL, path, and size.
    */
    public static function optimizeFilesystem(
        Filesystem $filesystem,
        bool $optimize = true,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        ?int $quality = null,
    ): Filesystem {
        // Download and optimize the image
        $optimizedPath = self::optimizeImageFromUrl(
            imageUrl: $filesystem->url,
            optimize: $optimize,
            maxWidth: $maxWidth,
            maxHeight: $maxHeight,
            quality: $quality,
        );

        $app = $filesystem->app;
        $company = $filesystem->company;

        try {
            // Get the filesystem service for re-uploading
            $filesystemService = new FilesystemServices($app, $company);
            $storage = $filesystemService->getStorageByDisk();

            // Get upload path from app config
            $uploadPath = $app->get('cloud-bucket-path') ?? '/';

            // Generate new filename with optimized suffix
            $originalName = Str::before(Str::before($filesystem->name, '?'), '#');
            $pathInfo = pathinfo($originalName);
            $baseName = $pathInfo['filename'] ?? 'optimized';
            $extension = $pathInfo['extension'] ?? pathinfo($optimizedPath, PATHINFO_EXTENSION);
            $newFilename = $baseName . '_optimized_' . time() . '.' . $extension;

            // Upload the optimized file
            $uploadedPath = $storage->putFileAs(
                $uploadPath,
                new File($optimizedPath),
                $newFilename,
                ['visibility' => 'public']
            );

            // Get new URL and file size
            $newUrl = $storage->url($uploadedPath);
            $newSize = filesize($optimizedPath);

            // Update the filesystem entity
            $filesystem->update([
                'url' => $newUrl,
                'path' => $storage->path($uploadedPath),
                'name' => $newFilename,
                'size' => $newSize,
            ]);

            return $filesystem->fresh();
        } finally {
            // Clean up temp file
            if (file_exists($optimizedPath)) {
                @unlink($optimizedPath);
            }
        }
    }

    public static function optimizeImageFromUrl(
        string $imageUrl,
        bool $optimize = true,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        ?int $quality = null,
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
            if ($optimize) {
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
            }
        } catch (Exception $e) {
            report($e);
        }

        /**
         * -----------------------------------------------------------
         * 3) QUALITY-BASED COMPRESSION (NO DIMENSION CHANGE)
         * -----------------------------------------------------------
         * Reduces file size by re-encoding at specified quality level.
         * Works for JPEG, PNG, and WebP formats.
         */
        if ($quality !== null && $quality >= 1 && $quality <= 100) {
            try {
                $manager = self::manager();
                $img = $manager->read($imagePath);

                if (self::isJpeg($extension)) {
                    $img->save($imagePath, quality: $quality);
                } elseif (self::isPng($extension)) {
                    // PNG compression level (0-9), convert quality to compression
                    $img->toPng()->save($imagePath);
                } elseif (self::isWebp($extension)) {
                    $img->toWebp($quality)->save($imagePath);
                }
            } catch (Exception $e) {
                report($e);
            }
        }

        return $imagePath;
    }

    private static function isJpeg(string $ext): bool
    {
        return in_array($ext, ['jpg', 'jpeg'], true);
    }

    private static function isPng(string $ext): bool
    {
        return $ext === 'png';
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
