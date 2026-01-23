<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Http\File;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\ImageManager;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use RuntimeException;

class ConvertHeicToJpgActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Filesystem $filesystem, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $fileType = strtolower($filesystem->file_type);
        if ($fileType !== 'heic') {
            return $this->failWorkflow([
                'success' => false,
                'message' => 'File is not a HEIC image',
                'file_type' => $fileType,
            ]);
        }

        return $this->executeIntegration(
            entity: $filesystem,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function ($filesystem, $app, $integrationCompany, $additionalParams) use ($params) {
                // Download the HEIC file
                $heicPath = $this->downloadFile($filesystem->url);

                // Convert HEIC to JPG
                $jpgPath = $this->convertHeicToJpg(
                    $heicPath,
                    $params['quality'] ?? 90
                );

                // Upload the converted JPG
                $newFilesystem = $this->uploadConvertedFile(
                    $jpgPath,
                    $filesystem,
                    $app
                );

                // Update the original filesystem entity with new data
                $filesystem->update([
                    'url' => $newFilesystem->url,
                    'path' => $newFilesystem->path,
                    'name' => $newFilesystem->name,
                    'file_type' => 'jpg',
                    'size' => $newFilesystem->size,
                ]);

                // Clean up temp files
                $this->cleanupTempFiles([$heicPath, $jpgPath]);

                return [
                    'success' => true,
                    'message' => 'HEIC successfully converted to JPG',
                    'filesystem_id' => $filesystem->id,
                    'original_url' => $newFilesystem->url,
                    'new_url' => $filesystem->url,
                    'original_size' => $newFilesystem->size,
                    'new_size' => $filesystem->size,
                ];
            },
            company: $filesystem->company,
        );
    }

    protected function downloadFile(string $url): string
    {
        $tempPath = storage_path('app/temp');
        if (! is_dir($tempPath)) {
            if (! mkdir($tempPath, 0755, true) && ! is_dir($tempPath)) {
                throw new RuntimeException("Failed to create temp directory at: $tempPath");
            }
        }

        if (! chdir($tempPath)) {
            throw new RuntimeException("Failed to change directory to: $tempPath");
        }

        $downloadedPath = FilesystemServices::downloadImageFromUrl($url);
        if ($downloadedPath === null || ! file_exists($downloadedPath)) {
            throw new RuntimeException('Failed to download HEIC file from URL');
        }

        return $downloadedPath;
    }

    protected function convertHeicToJpg(string $heicPath, int $quality = 90): string
    {
        try {
            $manager = new ImageManager(new Driver());
            $image = $manager->read($heicPath);

            // Generate JPG filename
            $pathInfo = pathinfo($heicPath);
            $jpgPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_converted_' . time() . '.jpg';

            // Convert and save as JPG
            $image->toJpeg($quality)->save($jpgPath);

            if (! file_exists($jpgPath)) {
                throw new RuntimeException('JPG file was not created');
            }

            return $jpgPath;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to convert HEIC to JPG: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function uploadConvertedFile(string $jpgPath, Filesystem $originalFilesystem, AppInterface $app): Filesystem
    {
        $company = $originalFilesystem->company;
        $filesystemService = new FilesystemServices($app, $company);
        $storage = $filesystemService->getStorageByDisk();

        // Get upload path from app config
        $uploadPath = $app->get('cloud-bucket-path') ?? '/';

        // Generate new filename
        $originalName = pathinfo($originalFilesystem->name, PATHINFO_FILENAME);
        $newFilename = $originalName . '_converted_' . time() . '.jpg';

        // Upload the JPG file
        $uploadedPath = $storage->putFileAs(
            $uploadPath,
            new File($jpgPath),
            $newFilename,
            ['visibility' => 'public']
        );

        if ($uploadedPath === false) {
            throw new RuntimeException('Failed to upload JPG file to cloud storage');
        }

        // Get new URL and file size (following ImageOptimizerService pattern)
        $newUrl = $storage->url($uploadedPath);
        $storagePath = $storage->path($uploadedPath);
        $newSize = filesize($jpgPath);

        if ($newSize === false) {
            throw new RuntimeException('Failed to get file size of converted JPG');
        }

        // Create a new temporary filesystem entity to return upload info
        $tempFilesystem = new Filesystem();
        $tempFilesystem->url = $newUrl;
        $tempFilesystem->path = $storagePath ?: $uploadedPath;
        $tempFilesystem->name = $newFilename;
        $tempFilesystem->size = (string) $newSize;

        return $tempFilesystem;
    }

    protected function cleanupTempFiles(array $filePaths): void
    {
        foreach ($filePaths as $filePath) {
            if ($filePath && file_exists($filePath)) {
                @unlink($filePath);
            }
        }
    }
}
