<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Str;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Services\ImageConversionService;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class ConvertHeicToJpgActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Filesystem|FilesystemEntities $filesystem, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $filesystemEntity = null;
        if ($filesystem instanceof FilesystemEntities) {
            $filesystemEntity = $filesystem;
            $filesystem = $filesystem->filesystem;
        }

        $fileType = strtolower($filesystem->file_type);
        if ($fileType !== 'heic' && $fileType !== 'heif') {
            return $this->failWorkflow([
                'success' => false,
                'message' => 'File is not a HEIC or HEIF image',
                'file_type' => $fileType,
            ]);
        }

        return $this->executeIntegration(
            entity: $filesystem,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function ($filesystem, $app, $_integrationCompany, $_additionalParams) use ($params, $filesystemEntity) {
                $originalUrl = $filesystem->url;
                $originalSize = $filesystem->size;

                // Convert HEIC/HEIF to JPG using the ImageConversionService
                $convertedFilesystem = ImageConversionService::convertFilesystem(
                    filesystem: $filesystem,
                    targetFormat: 'jpg',
                    quality: $params['quality'] ?? 90,
                );

                // Update field_name to have .jpg extension
                $fieldName = (string) $filesystem->field_name;
                $updatedFieldName = Str::endsWith($fieldName, ['.heic', '.HEIC', '.heif', '.HEIF'])
                    ? Str::beforeLast($fieldName, '.') . '.jpg'
                    : $fieldName . '.jpg';

                $filesystemEntity?->update([
                    'field_name' => $updatedFieldName,
                ]);

                return [
                    'success' => true,
                    'message' => 'HEIC/HEIF successfully converted to JPG',
                    'filesystem_id' => $convertedFilesystem->id,
                    'original_url' => $originalUrl,
                    'new_url' => $convertedFilesystem->url,
                    'original_size' => $originalSize,
                    'new_size' => $convertedFilesystem->size,
                ];
            },
            company: $filesystem->company,
        );
    }
}
