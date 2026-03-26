<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Enums\AllowedFileExtensionEnum;
use Kanvas\Filesystem\Services\FilesystemServices;

trait HasMutationUploadFiles
{
    public function uploadFileToEntity(
        Model $model,
        AppInterface $app,
        UserInterface $user,
        array $request
    ): Model {
        $files = isset($request['file']) ? [$request['file']] : $request['files'];
        $stableFiles = $this->copyToStablePaths($files);

        try {
            return $this->handleFileUpload($model, $app, $user, $stableFiles);
        } finally {
            $this->cleanupStableFiles($stableFiles);
        }
    }

    /**
     * Handle file upload(s) to the entity.
     * Files must already be on stable paths (not Swoole temp files).
     *
     * @throws Exception
     */
    private function handleFileUpload(
        Model $model,
        AppInterface $app,
        UserInterface $user,
        array $files,
        ?array $params = []
    ): Model {
        $filesystem = new FilesystemServices(
            $app,
            isset($model->company) && $model->company instanceof CompanyInterface ? $model->company : null
        );

        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension());

            if (! in_array($extension, AllowedFileExtensionEnum::WORK_FILES->getAllowedExtensions())) {
                throw new Exception('Invalid file format ' . $extension);
            }

            $filesystemEntity = $filesystem->upload($file, $user);

            $action = new AttachFilesystemAction($filesystemEntity, $model);
            $action->execute($file->getClientOriginalName());
        }

        return $model;
    }

    /**
     * Copy uploaded files to stable temp paths so Swoole/Octane
     * doesn't clean them up before processing completes.
     *
     * @param UploadedFile[] $files
     * @return UploadedFile[]
     */
    private function copyToStablePaths(array $files): array
    {
        $stable = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                $stable[] = $file;

                continue;
            }

            $originalPath = $file->getRealPath();

            if ($originalPath === false || ! is_readable($originalPath)) {
                throw new Exception('Uploaded file is no longer available: ' . $file->getClientOriginalName());
            }

            $stablePath = sys_get_temp_dir() . '/kanvas_' . uniqid('', true) . '.' . $file->getClientOriginalExtension();
            copy($originalPath, $stablePath);

            $stable[] = new UploadedFile(
                $stablePath,
                $file->getClientOriginalName(),
                $file->getClientMimeType(),
                $file->getError(),
                true
            );
        }

        return $stable;
    }

    /**
     * @param UploadedFile[] $files
     */
    private function cleanupStableFiles(array $files): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->getRealPath();

            if ($path !== false && file_exists($path) && str_starts_with($path, sys_get_temp_dir() . '/kanvas_')) {
                @unlink($path);
            }
        }
    }

    public function uploadImageToEntity(
        Model $model,
        AppInterface $app,
        UserInterface $user,
        UploadedFile $file,
        string $fieldName
    ): Model {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, AllowedFileExtensionEnum::ONLY_IMAGES->getAllowedExtensions())) {
            throw new Exception('Invalid file format ' . $extension);
        }

        $stableFiles = $this->copyToStablePaths([$file]);
        $stableFile = $stableFiles[0];

        try {
            $filesystem = new FilesystemServices(
                $app,
                isset($model->company) && $model->company instanceof CompanyInterface ? $model->company : null
            );
            $filesystemEntity = $filesystem->upload($stableFile, $user);
            new AttachFilesystemAction($filesystemEntity, $model)->execute($fieldName);
        } finally {
            $this->cleanupStableFiles($stableFiles);
        }

        return $model;
    }
}
