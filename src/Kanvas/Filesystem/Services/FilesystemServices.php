<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Baka\Contracts\CompanyInterface;
use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Actions\CreateFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem as ModelsFilesystem;
use Kanvas\Users\Models\Users;

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

        $createFileSystem = new CreateFilesystemAction($file, $user, $this->app, $this->company);

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
            'visibility_handler' => \League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility::class, // optional: set to \League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility::class to enable uniform bucket level access
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
            'use_path_style_endpoint' => (bool)$this->app->get('use_path_style_endpoint') ?? false,
            'endpoint' => $aws['endpoint'] ?? null,
        ]);
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

        $fileContent = $diskS3->get($path);
        $filename = basename($path);
        $path = storage_path('app/csv/' . $filename);
        file_put_contents($path, $fileContent);

        return $path;
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
                mime_content_type($tempFilePath), // MIME type
                null,                        // Error (null means no error)
                true                         // Mark it as a test file (will not delete original file)
            ),
            $user
        );
    }

    public static function downloadImageFromUrl(string $imageUrl): ?string
    {
        $fileInfo = pathinfo($imageUrl);
        $extension = $fileInfo['extension'] ?? null;

        if (is_null($extension)) {
            $parsedUrl = parse_url($imageUrl);
            $path = $parsedUrl['path'];
            $extension = pathinfo($path, PATHINFO_EXTENSION);
        }

        $tempFilePath = sys_get_temp_dir() . '/' . uniqid() . '-' . bin2hex(random_bytes(4)) . '.' . $extension;

        try {
            // Use Laravel HTTP client with retry logic
            $response = Http::timeout(30)
                ->retry(3, 100) // Retry 3 times with 100ms delay
                ->get($imageUrl);

            if ($response->successful()) {
                $imageContent = $response->body();

                Log::info('Downloaded the file', [
                    'url' => $imageUrl,
                    'size' => strlen($imageContent),
                    'content_type' => $response->header('Content-Type'),
                ]);

                file_put_contents($tempFilePath, $imageContent);
                return $tempFilePath;
            }

            Log::error('File request failed', [
                'url' => $imageUrl,
                'status' => $response->status(),
            ]);
        } catch (\Exception $e) {
            Log::error('File download exception', [
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    public function uploadFileFromUrl(string $fileUrl, Users $user): ModelsFilesystem
    {
        // Download the file to a temporary location
        $tempFilePath = $this->downloadFileFromUrl($fileUrl);

        if ($tempFilePath === null) {
            throw new InvalidArgumentException('Failed to download file from URL: ' . $fileUrl);
        }

        // Extract the original filename from the URL
        $parsedUrl = parse_url($fileUrl);
        $path = $parsedUrl['path'] ?? '';
        $originalName = basename($path);

        // If no filename found in URL, generate one
        if (empty($originalName) || strpos($originalName, '.') === false) {
            $mimeType = mime_content_type($tempFilePath);
            $extension = $this->getExtensionFromMimeType($mimeType);
            $originalName = uniqid('file_') . '.' . $extension;
        }

        try {
            // Create an UploadedFile instance and upload it
            $uploadedFile = new UploadedFile(
                $tempFilePath,
                $originalName,
                mime_content_type($tempFilePath),
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
            // Download with timeout and error handling
            $response = Http::timeout(30)
                ->throw()
                ->get($fileUrl);

            // Extract extension from URL or Content-Type header
            $extension = $this->getExtensionFromUrl($fileUrl);

            if (empty($extension)) {
                $contentType = $response->header('Content-Type');
                $extension = $this->getExtensionFromMimeType($contentType);
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

    protected function getExtensionFromUrl(string $fileUrl): string
    {
        $fileInfo = pathinfo(parse_url($fileUrl, PHP_URL_PATH) ?? '');

        return $fileInfo['extension'] ?? '';
    }

    /**
     * Get file extension from MIME type.
     */
    protected function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'application/zip' => 'zip',
            'application/json' => 'json',
            'text/csv' => 'csv',
        ];

        return $mimeMap[$mimeType] ?? 'bin';
    }
}
