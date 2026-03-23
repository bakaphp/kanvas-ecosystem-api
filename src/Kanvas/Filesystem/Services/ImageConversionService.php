<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Exception;
use Illuminate\Http\File;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\ImageManager;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Users\Models\Users;
use RuntimeException;

class ImageConversionService
{
    protected const array SUPPORTED_TARGET_FORMATS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
    ];
    protected const array SUPPORTED_SOURCE_FORMATS = [
        'heic',
        'heif',
        'jpg',
        'jpeg',
        'png',
        'webp',
        'gif',
        'bmp',
        'tiff',
        'tif',
    ];

    /**
     * Convert an existing Filesystem entity to a different format and update it.
     *
     * Downloads the image from the filesystem URL, converts it to the target format,
     * re-uploads to cloud storage, and updates the Filesystem record.
     */
    public static function convertFilesystem(
        Filesystem $filesystem,
        string $targetFormat,
        ?int $quality = null,
    ): Filesystem {
        $targetFormat = strtolower($targetFormat);
        self::validateTargetFormat($targetFormat);

        // Download and convert the image
        $convertedPath = self::convertImageFromUrl(
            imageUrl: $filesystem->url,
            targetFormat: $targetFormat,
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

            // Generate new filename with converted extension
            $originalName = Str::before(Str::before($filesystem->name, '?'), '#');
            $pathInfo = pathinfo($originalName);
            $baseName = $pathInfo['filename'] ?? 'converted';
            $newFilename = $baseName . '_converted_' . time() . '.' . $targetFormat;

            // Upload the converted file
            $uploadedPath = $storage->putFileAs(
                $uploadPath,
                new File($convertedPath),
                $newFilename,
                ['visibility' => 'public']
            );

            if ($uploadedPath === false) {
                throw new RuntimeException('Failed to upload converted file to cloud storage');
            }

            // Get new URL and file size
            $newUrl = $storage->url($uploadedPath);
            $newSize = filesize($convertedPath);

            // Update the filesystem entity
            $filesystem->update([
                'url' => $newUrl,
                'path' => $storage->path($uploadedPath),
                'name' => $newFilename,
                'file_type' => $targetFormat === 'jpeg' ? 'jpg' : $targetFormat,
                'size' => $newSize,
            ]);

            $filesystem->refresh();

            return $filesystem;
        } finally {
            // Clean up temp file
            if (file_exists($convertedPath)) {
                @unlink($convertedPath);
            }
        }
    }

    /**
     * Convert an image from URL to a different format.
     *
     * @return string Path to the converted image file
     */
    public static function convertImageFromUrl(
        string $imageUrl,
        string $targetFormat,
        ?int $quality = null,
    ): string {
        $targetFormat = strtolower($targetFormat);
        self::validateTargetFormat($targetFormat);

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

        $sourceExtension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        self::validateSourceFormat($sourceExtension);

        // If source and target are the same, just return the path
        if (self::isSameFormat($sourceExtension, $targetFormat)) {
            return $imagePath;
        }

        try {
            return self::convertImage($imagePath, $targetFormat, $quality);
        } catch (Exception $e) {
            // Clean up the downloaded file on error
            if (file_exists($imagePath)) {
                @unlink($imagePath);
            }

            throw $e;
        }
    }

    /**
     * Convert a local image file to a different format.
     *
     * @return string Path to the converted image file
     */
    public static function convertImage(
        string $sourcePath,
        string $targetFormat,
        ?int $quality = null,
    ): string {
        $targetFormat = strtolower($targetFormat);
        self::validateTargetFormat($targetFormat);

        if (! file_exists($sourcePath)) {
            throw new RuntimeException("Source file does not exist: $sourcePath");
        }

        $quality = $quality ?? 90;

        try {
            $manager = self::manager();
            $image = $manager->read($sourcePath);

            // Generate target filename
            $pathInfo = pathinfo($sourcePath);
            $targetPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_converted_' . time() . '.' . $targetFormat;

            // Convert and save to target format
            match ($targetFormat) {
                'jpg', 'jpeg' => $image->toJpeg($quality)->save($targetPath),
                'png' => $image->toPng()->save($targetPath),
                'webp' => $image->toWebp($quality)->save($targetPath),
                default => throw new RuntimeException("Unsupported target format: $targetFormat"),
            };

            if (! file_exists($targetPath)) {
                throw new RuntimeException('Converted file was not created');
            }

            return $targetPath;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to convert image: ' . $e->getMessage(), 0, $e);
        }
    }

    protected static function validateTargetFormat(string $format): void
    {
        if (! in_array($format, self::SUPPORTED_TARGET_FORMATS, true)) {
            throw new RuntimeException(
                "Unsupported target format: $format. Supported formats: " . implode(', ', self::SUPPORTED_TARGET_FORMATS)
            );
        }
    }

    protected static function validateSourceFormat(string $format): void
    {
        if (! in_array($format, self::SUPPORTED_SOURCE_FORMATS, true)) {
            throw new RuntimeException(
                "Unsupported source format: $format. Supported formats: " . implode(', ', self::SUPPORTED_SOURCE_FORMATS)
            );
        }
    }

    protected static function isSameFormat(string $source, string $target): bool
    {
        // Normalize jpg/jpeg
        $source = $source === 'jpeg' ? 'jpg' : $source;
        $target = $target === 'jpeg' ? 'jpg' : $target;

        return $source === $target;
    }

    /**
     * Get a viewable image URL by finding the Filesystem by URL and converting if needed.
     *
     * If the URL is a HEIC/HEIF, finds the Filesystem record, converts it to JPG,
     * and returns the new URL. If no Filesystem exists, creates one from the URL.
     * Otherwise returns the original URL.
     */
    public static function getViewableUrl(
        string $imageUrl,
        AppInterface $app,
        ?int $quality = null,
        ?Users $user = null,
        ?CompanyInterface $company = null
    ): string {
        $extension = self::getExtensionFromUrl($imageUrl);

        if (! self::needsConversion($extension)) {
            return $imageUrl;
        }

        $filesystem = Filesystem::fromApp($app)->where('url', $imageUrl)->first();

        if ($filesystem === null && $user !== null && $app instanceof Apps) {
            $filesystemService = new FilesystemServices($app, $company);
            $filesystem = $filesystemService->uploadFileFromUrl($imageUrl, $user);
        }

        if ($filesystem === null) {
            return $imageUrl;
        }

        $converted = self::convertFilesystem($filesystem, 'jpg', $quality);

        return $converted->url;
    }

    /**
     * Check if the image format needs conversion to be viewable in browsers/PDFs.
     */
    public static function needsConversion(string $extension): bool
    {
        $extension = strtolower($extension);

        return in_array($extension, ['heic', 'heif', 'tiff', 'tif', 'bmp'], true);
    }

    /**
     * Extract file extension from URL (handles query strings).
     */
    protected static function getExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null || $path === false) {
            return '';
        }

        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    protected static function manager(): ImageManager
    {
        return new ImageManager(new Driver());
    }
}
