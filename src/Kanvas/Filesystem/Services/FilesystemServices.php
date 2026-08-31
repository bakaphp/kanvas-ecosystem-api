<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Baka\Contracts\CompanyInterface;
use Baka\Http\SafeUrl;
use Exception;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Actions\CreateFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem as ModelsFilesystem;
use Kanvas\Users\Models\Users;
use League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;

class FilesystemServices
{
    protected Filesystem $storage;

    /**
     * Construct function.
     */
    public function __construct(
        protected Apps $app,
        protected ?CompanyInterface $company = null
    ) {
        $this->storage = $this->getStorageByDisk();
    }

    /**
     * Upload a file using filesystem.
     */
    public function upload(UploadedFile $file, Users $user): ModelsFilesystem
    {
        $file = $this->optimizeBeforeUpload($file);

        $path = $this->app->get('cloud-bucket-path') ?? '/';
        $preserveOriginalName = (bool) ($this->app->get('filesystem-preserve-original-filename') ?? false);

        if ($preserveOriginalName) {
            $originalName = $file->getClientOriginalName();
            $filename = $originalName;
            $counter = 1;

            // Check if file exists and modify name if necessary to avoid collision
            while ($this->storage->exists(rtrim($path, '/') . '/' . $filename)) {
                $pathInfo = pathinfo($originalName);
                $name = $pathInfo['filename'] ?? pathinfo($originalName, PATHINFO_FILENAME);
                $extension = $pathInfo['extension'] ?? pathinfo($originalName, PATHINFO_EXTENSION);
                $filename = $name . '_' . $counter . '.' . $extension;
                $counter++;
            }

            $uploadedFile = $this->storage->putFileAs(
                $path,
                $file,
                $filename,
                [
                    'visibility' => 'public',
                ]
            );
        } else {
            // Keep existing behavior (generates unique filename)
            $uploadedFile = $this->storage->put(
                $path,
                $file,
                [
                    'visibility' => 'public',
                ]
            );
        }

        $createFileSystem = new CreateFilesystemAction(
            $file,
            $user,
            $this->app,
            $this->company
        );

        return $createFileSystem->execute(
            $this->storage->url($uploadedFile),
            $this->storage->path($uploadedFile)
        );
    }

    /**
     * Build an on-demand Storage client based on the config filesystem service
     * of the current app.
     */
    public function getStorageByDisk(): Filesystem
    {
        return match ($this->app->get('filesystem-service')) {
            'gcs' => $this->buildGoogleCloudStorage(),
            's3' => $this->buildS3Storage(),
            default => $this->buildS3Storage(),
        };
    }

    /**
     * Build an on-demand google cloud storage using the (must) already saved service account-file.
     */
    public function buildGoogleCloudStorage(): Filesystem
    {
        if (empty($this->app->get('service-account-file')) || empty($this->app->get('cloud-bucket'))) {
            throw new ValidationException('Missing Google Cloud Storage credentials');
        }

        return Storage::build([
            'driver' => 'gcs',
            'key_file' => $this->app->get('service-account-file'), // optional: Array of data that substitutes the .json file (see below)
            'bucket' => $this->app->get('cloud-bucket'),
            'storage_api_uri' => $this->app->get('cloud-cdn'), // see: Public URLs below
            'apiEndpoint' => null, // set storageClient apiEndpoint
            'visibility' => 'public', // optional: public|private
            'visibility_handler' => UniformBucketLevelAccessVisibility::class, // optional: set to \League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility::class to enable uniform bucket level access
            'metadata' => ['cacheControl' => 'public,max-age=86400'], // optional: default metadata
        ]);
    }

    /**
     * Build an on-demand aws s3 storage using the (must) already saved service-account-file
     */
    public function buildS3Storage(): Filesystem
    {
        $aws = (array) $this->app->get('service-account-file');

        if (empty($aws['key']) || empty($aws['secret']) || empty($aws['region'])) {
            throw new ValidationException('Missing AWS credentials');
        }

        return Storage::build([
            'driver' => 's3',
            'key' => $aws['key'],
            'secret' => $aws['secret'],
            'region' => $aws['region'],
            'bucket' => $this->app->get('cloud-bucket'),
            'url' => $this->app->get('cloud-cdn'),
            'path' => $this->app->get('cloud-bucket-path') ?? '/',
            'use_path_style_endpoint' => (bool) ($this->app->get('use_path_style_endpoint') ?? false),
            'endpoint' => $aws['endpoint'] ?? null,
        ]);
    }

    protected function optimizeBeforeUpload(UploadedFile $file): UploadedFile
    {
        if (! $this->app->get('filesystem-optimize-on-upload')) {
            return $file;
        }

        $maxWidth = (int) ($this->app->get('filesystem-optimize-max-width') ?: 0) ?: null;
        $maxHeight = (int) ($this->app->get('filesystem-optimize-max-height') ?: 0) ?: null;
        $quality = (int) ($this->app->get('filesystem-optimize-quality') ?: 0) ?: null;

        ImageOptimizerService::optimizeLocalFile(
            filePath: $file->getRealPath(),
            optimize: true,
            maxWidth: $maxWidth,
            maxHeight: $maxHeight,
            quality: $quality,
        );

        return $file;
    }

    /**
     * Delete a file from the filesystem cloud service.
     */
    public function delete(ModelsFilesystem $file): bool
    {
        return $this->storage->delete($file->path);
    }

    public function getFileLocalPath(ModelsFilesystem $filesystem): string
    {
        $path = $filesystem->path;
        $diskS3 = $this->buildS3Storage();

        $directory = storage_path('app/csv');
        File::ensureDirectoryExists($directory);

        $localPath = $directory . '/' . basename($path);

        // Stream-pipe the remote file to disk in ~8KB chunks instead of
        // buffering the whole thing into a PHP string with $diskS3->get().
        // Memory cost stays at one chunk regardless of file size, so a
        // multi-hundred-MB CSV no longer OOMs the worker on download.
        $remoteStream = $diskS3->readStream($path);
        if ($remoteStream === null) {
            throw new RuntimeException('Could not open remote stream for ' . $path);
        }

        $localStream = @fopen($localPath, 'w');
        if ($localStream === false) {
            fclose($remoteStream);

            throw new RuntimeException('Could not open local file for writing at ' . $localPath);
        }

        try {
            stream_copy_to_stream($remoteStream, $localStream);
        } finally {
            fclose($localStream);
            fclose($remoteStream);
        }

        return $localPath;
    }

    public function createFileSystemFromBase64(string $base64String, string $originalName, Users $user): ModelsFilesystem
    {
        /**
         * @todo should we cache the decoded content? to avoid decoding it again
         */
        $decodedContent = base64_decode($base64String);

        // Ensure the content is decoded correctly
        if ($decodedContent === false) {
            throw new InvalidArgumentException('Invalid Base64 string provided');
        }

        // Save to a temporary file
        $tempFilePath = sys_get_temp_dir() . '/' . uniqid() . '_' . $originalName;
        file_put_contents($tempFilePath, $decodedContent);

        return $this->upload(
            new UploadedFile(
                $tempFilePath,               // Path to the file
                $originalName,               // Original file name
                self::detectMimeType($tempFilePath), // MIME type
                null,                        // Error (null means no error)
                true                         // Mark it as a test file (will not delete original file)
            ),
            $user
        );
    }

    public static function downloadImageFromUrl(string $imageUrl): ?string
    {
        SafeUrl::assertSafe($imageUrl);

        $fileInfo = pathinfo($imageUrl);
        $extension = $fileInfo['extension'] ?? null;

        if (is_null($extension)) {
            $parsedUrl = parse_url($imageUrl);
            $path = $parsedUrl['path'];
            $extension = pathinfo($path, PATHINFO_EXTENSION);
        }

        $dirPath = storage_path('app/temp');

        // Ensure directory exists
        // if (! is_dir($dirPath)) {
        //     mkdir($dirPath, 0755, true);
        // }

        $tempFilePath = $dirPath . '/' . uniqid() . '-' . bin2hex(random_bytes(4)) . '.' . $extension;

        try {
            // Use Laravel HTTP client with retry logic
            $response = Http::timeout(30)
                ->retry(3, 100) // Retry 3 times with 100ms delay
                ->get($imageUrl);

            if ($response->successful()) {
                $imageContent = $response->body();

                file_put_contents($tempFilePath, $imageContent);

                return $tempFilePath;
            }
        } catch (Exception $e) {
            report($e);
        }

        return null;
    }

    /**
     * `$fileName` is the name to STORE it under. A caller that validated a name against an
     * allow-list must pass it here: deriving the name from the URL instead stores something the
     * check never saw, so `attach_file_to_plan(file_url: '.../x.exe', file_name: 'report.md')`
     * would pass the tool's extension gate and land as `x.exe`.
     */
    public function uploadFileFromUrl(string $fileUrl, Users $user, ?string $fileName = null): ModelsFilesystem
    {
        // Download the file to a temporary location
        $tempFilePath = $this->downloadFileFromUrl($fileUrl);

        if ($tempFilePath === null) {
            throw new InvalidArgumentException('Failed to download file from URL: ' . $fileUrl);
        }

        $originalName = trim((string) $fileName) !== ''
            ? basename(str_replace('\\', '/', (string) $fileName))
            : basename(parse_url($fileUrl, PHP_URL_PATH) ?? '');

        // Neither the caller nor the URL gave a usable name
        $mimeType = self::detectMimeType($tempFilePath);
        if (empty($originalName) || strpos($originalName, '.') === false) {
            $extension = self::getExtensionFromMimeType($mimeType);
            $originalName = uniqid('file_') . '.' . $extension;
        }

        try {
            // Create an UploadedFile instance and upload it
            $uploadedFile = new UploadedFile(
                $tempFilePath,
                $originalName,
                $mimeType,
                null,
                true
            );

            return $this->upload($uploadedFile, $user);
        } finally {
            // Clean up the temporary file
            if (file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
        }
    }

    protected function downloadFileFromUrl(string $fileUrl): ?string
    {
        try {
            SafeUrl::assertSafe($fileUrl);

            // Download with timeout and error handling
            $response = Http::timeout(30)
                ->throw()
                ->get($fileUrl);

            // Extract extension from URL or Content-Type header
            $extension = self::getExtensionFromUrl($fileUrl);

            if (empty($extension)) {
                $contentType = $response->header('Content-Type');
                $extension = self::getExtensionFromMimeType($contentType);
            }

            $tempFilePath = sys_get_temp_dir() . '/' . uniqid() . '.' . $extension;

            // Write the response body to file
            file_put_contents($tempFilePath, $response->body());

            return $tempFilePath;
        } catch (Exception $e) {
            report($e);

            return null;
        }
    }

    /**
     * Extract the file extension from a URL, ignoring query strings. Lower-cased.
     */
    public static function getExtensionFromUrl(string $fileUrl): string
    {
        $path = parse_url($fileUrl, PHP_URL_PATH);
        if (! is_string($path)) {
            return '';
        }

        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Get file extension from MIME type.
     */
    public static function getExtensionFromMimeType(string $mimeType): string
    {
        return MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? 'bin';
    }

    public static function detectMimeType(string $filePath): string
    {
        if (is_readable($filePath)) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detected = @$finfo->file($filePath);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'json' => 'application/json',
            default => 'application/octet-stream',
        };
    }

    public static function resolveExtensionFromFile(string $filePath): string
    {
        return self::getExtensionFromMimeType(
            self::detectMimeType($filePath)
        );
    }
}
