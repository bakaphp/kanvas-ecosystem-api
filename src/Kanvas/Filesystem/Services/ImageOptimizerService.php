<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Baka\Support\Str;
use Exception;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Kanvas\Filesystem\Models\Filesystem;
use RuntimeException;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\Optimizers\Jpegoptim;
use Spatie\ImageOptimizer\Optimizers\Optipng;
use Throwable;

class ImageOptimizerService
{
    protected const MAX_LOCAL_OPTIMIZE_FILE_SIZE_BYTES = 25 * 1024 * 1024;

    /**
     * optipng is lossless and gains next to nothing on photographic PNGs, yet its cost grows with pixel
     * count: a 12MP phone photo takes tens of seconds and makes the upload time out on the client.
     */
    protected const OPTIPNG_MAX_PIXELS = 4_000_000;

    /** The upload request waits on the optimizer chain, so it is capped well under the mobile clients' timeout. */
    protected const OPTIMIZER_TIMEOUT_SECONDS = 15;

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

        return self::optimizeLocalFile(
            filePath: $imagePath,
            optimize: $optimize,
            maxWidth: $maxWidth,
            maxHeight: $maxHeight,
            quality: $quality,
        );
    }

    /**
     * Optimize a local file in place. Every step is best-effort: a failure leaves the file as it was.
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

        $fileSize = @filesize($filePath);
        if (is_int($fileSize) && $fileSize > self::MAX_LOCAL_OPTIMIZE_FILE_SIZE_BYTES) {
            // Avoid memory pressure when mime/image libraries inspect very large uploads.
            return $filePath;
        }

        $extension = self::resolveExtension($filePath);
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $filePath;
        }

        $isPng = self::isPng($extension);
        $resize = $maxWidth !== null || $maxHeight !== null;
        $quality = $quality !== null && $quality >= 1 && $quality <= 100 ? $quality : null;
        // PNG has no quality knob: re-encoding it at the same size costs seconds and changes nothing.
        $recompress = $quality !== null && ! $isPng;

        // One decode/encode pass for both; a second lossy encode only costs time and quality.
        if ($resize || $recompress) {
            try {
                $img = self::manager()->decodePath($filePath);
                if ($resize) {
                    $img = $img->scale($maxWidth, $maxHeight);
                }

                self::saveWithFormat(
                    $img,
                    $filePath,
                    $extension,
                    $isPng ? null : ($quality ?? 90)
                );
            } catch (Exception $e) {
                report($e);
            }
        }

        if ($optimize) {
            self::optimizeInPlace($filePath, $extension);
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

                self::saveWithFormat($img, $filePath, $extension, 85);
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
        return FilesystemServices::resolveExtensionFromFile($filePath);
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

    /**
     * Save image with explicit format encoding.
     * In v4, save() infers format from file extension — files without extensions
     * (e.g. /tmp/phpXXXXXX from uploads) need explicit format encoding.
     */
    protected static function saveWithFormat(
        ImageInterface $img,
        string $filePath,
        string $extension,
        ?int $quality = null,
    ): void {
        $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $knownExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tiff', 'tif', 'avif'];

        if (in_array($fileExtension, $knownExtensions, true)) {
            $img->save($filePath, quality: $quality);

            return;
        }

        $format = match (true) {
            self::isJpeg($extension) => 'jpg',
            self::isPng($extension) => 'png',
            self::isWebp($extension) => 'webp',
            default => 'jpg',
        };

        $encoded = $img->encodeUsingFileExtension($format, quality: $quality);
        file_put_contents($filePath, (string) $encoded);
    }

    protected static function manager(): ImageManager
    {
        return new ImageManager(Driver::class);
    }

    /**
     * optipng rewrites its target in place (original parked at "<file>.bak") and the chain SIGKILLs it
     * on timeout, which leaves a truncated image at the target. Optimize a sibling copy and swap it in
     * only once the whole chain has succeeded.
     */
    private static function optimizeInPlace(string $filePath, string $extension): void
    {
        if (self::isPng($extension) && self::pixelCount($filePath) > self::OPTIPNG_MAX_PIXELS) {
            return;
        }

        $candidate = $filePath . '.optimizing';

        try {
            static::optimizerChain()->optimize($filePath, $candidate);

            clearstatcache(true, $candidate);
            if (! is_file($candidate) || filesize($candidate) === 0) {
                throw new RuntimeException('Optimizer chain produced no output');
            }

            if (! rename($candidate, $filePath)) {
                throw new RuntimeException('Failed to swap in the optimized copy');
            }
        } catch (Throwable $e) {
            Log::warning('Image optimization skipped, keeping the unoptimized file: ' . $filePath, ['exception' => $e]);
        } finally {
            @unlink($candidate);
            @unlink($candidate . '.bak');
        }
    }

    private static function pixelCount(string $filePath): int
    {
        $info = @getimagesize($filePath);

        return $info === false ? 0 : (int) $info[0] * (int) $info[1];
    }

    /** Lossless optipng gains almost nothing on photos; -o1 keeps it cheap for the graphics it does help. */
    protected static function optimizerChain(): OptimizerChain
    {
        return new OptimizerChain()
            ->addOptimizer(new Optipng(['-i0', '-o1', '-quiet']))
            ->addOptimizer(new Jpegoptim(['-m85', '--strip-all', '--all-progressive']))
            ->setTimeout(self::OPTIMIZER_TIMEOUT_SECONDS)
            ->throws();
    }
}
