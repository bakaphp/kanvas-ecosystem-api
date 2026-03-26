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
        // Check if we're dealing with a single file or multiple files
        $files = isset($request['file']) ? [$request['file']] : $request['files'];

        return $this->handleFileUpload($model, $app, $user, $files);
    }

    /**
     * Handle file upload(s) to the entity.
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

            $file = $this->ensureFileIsReadable($file);

            // Upload file
            $filesystemEntity = $filesystem->upload($file, $user);

            // Attach file to the entity
            $action = new AttachFilesystemAction($filesystemEntity, $model);
            $action->execute($file->getClientOriginalName());
        }

        return $model;
    }

    /**
     * Ensure the uploaded file is still readable on disk.
     * Under Swoole/Octane, temp files can be cleaned up before processing completes.
     */
    private function ensureFileIsReadable(UploadedFile $file): UploadedFile
    {
        $path = $file->getRealPath();

        if ($path !== false && is_readable($path)) {
            return $file;
        }

        throw new Exception('Uploaded file is no longer available: ' . $file->getClientOriginalName());
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

        $file = $this->ensureFileIsReadable($file);

        $filesystem = new FilesystemServices(
            $app,
            isset($model->company) && $model->company instanceof CompanyInterface ? $model->company : null
        );
        $filesystemEntity = $filesystem->upload($file, $user);
        new AttachFilesystemAction($filesystemEntity, $model)->execute($fieldName);

        return $model;
    }
}
