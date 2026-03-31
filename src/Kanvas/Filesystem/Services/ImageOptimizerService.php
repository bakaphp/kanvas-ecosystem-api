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
         * 1) RESIZE (Intervention v4)
         * ----------------------------
         */
        if (($maxWidth !== null || $maxHeight !== null)
            && in_array($extension, $resizableExtensions, true)) {
            try {
                $manager = self::manager();
                $img = $manager->decodePath($imagePath);

                // scale() keeps aspect ratio automatically
                $img = $img->scale($maxWidth, $maxHeight);

                // Format-aware save
                switch ($extension) {
                    case 'jpg':
                    case 'jpeg':
                        $img->save($imagePath, quality: 90);

                        break;
                    case 'png':
                        $img->save($imagePath);

                        break;
                    case 'webp':
                        $img->save($imagePath, quality: 90);

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
                $img = $manager->decodePath($imagePath);

                if (self::isJpeg($extension)) {
                    $img->save($imagePath, quality: $quality);
                } elseif (self::isPng($extension)) {
                    $img->save($imagePath);
                } elseif (self::isWebp($extension)) {
                    $img->save($imagePath, quality: $quality);
                }
            } catch (Exception $e) {
                report($e);
            }
        }

        return $imagePath;
    }

    /**
     * Optimize a local file in-place before upload.
     * Unlike optimizeImageFromUrl(), this does not download — it works directly on the local path.
     */
    public static function optimizeLocalFile(
        string $filePath,
        bool $optimize = true,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        ?int $quality = null,
    ): string {
        if (! file_exists($filePath)) {
            return $filePath;
        }

        $extension = self::resolveExtension($filePath);
        $resizableExtensions = ['jpg', 'jpeg', 'png', 'webp'];

        if (! in_array($extension, $resizableExtensions, true)) {
            return $filePath;
        }

        // 1) Resize
        if ($maxWidth !== null || $maxHeight !== null) {
            try {
                $manager = self::manager();
                $img = $manager->decodePath($filePath);
                $img = $img->scale($maxWidth, $maxHeight);

                match (true) {
                    self::isJpeg($extension) => $img->save($filePath, quality: 90),
                    self::isPng($extension) => $img->save($filePath),
                    self::isWebp($extension) => $img->save($filePath, quality: 90),
                    default => null,
                };
            } catch (Exception $e) {
                report($e);
            }
        }

        // 2) Spatie optimization
        if ($optimize) {
            try {
                $optimizerChain = new OptimizerChain();
                $optimizerChain->addOptimizer(new Optipng(['-i0', '-o2', '-quiet']));
                $optimizerChain->addOptimizer(new Jpegoptim(['-m85', '--strip-all', '--all-progressive']));
                $optimizerChain
                    ->useLogger(Log::channel())
                    ->setTimeout(60)
                    ->optimize($filePath);
            } catch (Exception $e) {
                report($e);
            }
        }

        // 3) Quality compression
        if ($quality !== null && $quality >= 1 && $quality <= 100) {
            try {
                $manager = self::manager();
                $img = $manager->decodePath($filePath);

                match (true) {
                    self::isJpeg($extension) => $img->save($filePath, quality: $quality),
                    self::isPng($extension) => $img->save($filePath),
                    self::isWebp($extension) => $img->save($filePath, quality: $quality),
                    default => null,
                };
            } catch (Exception $e) {
                report($e);
            }
        }

        return $filePath;
    }

    /**
    * Reduce image file size to fit under maxFileSize bytes.
    *
    * Strategy:
    * 1. HEIC: convert to JPEG first (standard format for messaging)
    * 2. Estimate dimension scale via sqrt(target/current) and resize once if needed
    * 3. JPEG/HEIC→JPEG: use Imagick's jpeg:extent for single-pass quality optimization
    * 4. PNG: dimension scaling only (lossless format)
    */
    public static function constrainFileSize(
        string $filePath,
        int $maxFileSize,
    ): string {
        if (! file_exists($filePath)) {
            return $filePath;
        }

        clearstatcache(true, $filePath);
        $currentSize = filesize($filePath);
        if ($currentSize <= $maxFileSize) {
            return $filePath;
        }

        $extension = self::resolveExtension($filePath);
        $supportedExtensions = ['jpg', 'jpeg', 'png', 'heic', 'heif', 'avif'];

        if (! in_array($extension, $supportedExtensions, true)) {
            return $filePath;
        }

        try {
            // HEIC/HEIF: convert to JPEG first
            if (self::isHeic($extension)) {
                $filePath = self::convertToJpeg($filePath);
                $extension = 'jpeg';
                clearstatcache(true, $filePath);
                $currentSize = filesize($filePath);

                if ($currentSize <= $maxFileSize) {
                    return $filePath;
                }
            }

            // Phase 1: estimate and apply dimension scale in a single pass
            $manager = self::manager();
            $img = $manager->decodePath($filePath);
            $originalWidth = $img->width();
            $originalHeight = $img->height();

            $ratio = (float) $maxFileSize / (float) $currentSize;
            $dimensionScale = sqrt($ratio) * 0.85;

            if ($dimensionScale < 1.0) {
                $newWidth = (int) round((float) $originalWidth * $dimensionScale);
                $newHeight = (int) round((float) $originalHeight * $dimensionScale);

                $img = $img->scale($newWidth, $newHeight);

                match (true) {
                    self::isJpeg($extension) => $img->save($filePath, quality: 85),
                    self::isPng($extension) => $img->save($filePath),
                    default => null,
                };
                clearstatcache(true, $filePath);
            }

            // Phase 2: JPEG quality compression via jpeg:extent if still over limit
            if (self::isJpeg($extension) && filesize($filePath) > $maxFileSize) {
                self::constrainJpegWithExtent($filePath, $maxFileSize);
            }
            // PNG is lossless — dimension scaling in phase 1 is all we can do
        } catch (Exception $e) {
            report($e);
        }

        return $filePath;
    }

    /**
     * Use Imagick's jpeg:extent to find the highest quality that fits under maxFileSize in a single encode.
     */
    private static function constrainJpegWithExtent(string $filePath, int $maxFileSize): void
    {
        $manager = self::manager();
        $img = $manager->decodePath($filePath);

        $core = $img->core()->native();
        $core->setOption('jpeg:extent', (string) $maxFileSize); // @phpstan-ignore-line
        $core->setImageFormat('jpeg'); // @phpstan-ignore-line
        $core->writeImage('jpeg:' . $filePath); // @phpstan-ignore-line

        clearstatcache(true, $filePath);
    }

    /**
     * Convert HEIC/HEIF to JPEG via Imagick.
     */
    private static function convertToJpeg(string $filePath): string
    {
        $jpegPath = preg_replace('/\.(heic|heif)$/i', '.jpg', $filePath) ?? $filePath . '.jpg';
        if ($jpegPath === $filePath) {
            $jpegPath = $filePath . '.jpg';
        }

        $manager = self::manager();
        $img = $manager->decodePath($filePath);
        $img->save($jpegPath, quality: 90);

        @unlink($filePath);

        return $jpegPath;
    }

    /**
     * Resolve file extension via MIME type detection from file bytes.
     */
    private static function resolveExtension(string $filePath): string
    {
        $mimeType = mime_content_type($filePath);

        return FilesystemServices::getExtensionFromMimeType($mimeType);
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

    private static function isHeic(string $ext): bool
    {
        return in_array($ext, ['heic', 'heif'], true);
    }

    protected static function manager(): ImageManager
    {
        return new ImageManager(Driver::class);
    }
}
