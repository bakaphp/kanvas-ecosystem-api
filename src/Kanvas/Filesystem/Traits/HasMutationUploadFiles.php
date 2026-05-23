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
use Kanvas\Filesystem\Models\Filesystem;
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

        $this->handleFileUpload($model, $app, $user, $files);

        return $model;
    }

    /**
     * Variant of {@see uploadFileToEntity} that returns the uploaded `Filesystem` entities
     * instead of the parent model. Use this when the caller needs direct handles to the
     * uploaded files (extracting URLs, classifying, etc.) — `uploadFileToEntity` stays the
     * default for the simple "attach and move on" case.
     *
     * @param array<int, UploadedFile> $files
     * @return list<Filesystem>
     *
     * @throws Exception
     */
    public function uploadFilesAndCollect(
        Model $model,
        AppInterface $app,
        UserInterface $user,
        array $files,
        AllowedFileExtensionEnum $allowed = AllowedFileExtensionEnum::WORK_FILES,
    ): array {
        return $this->handleFileUpload($model, $app, $user, $files, $allowed);
    }

    /**
     * Upload each file via FilesystemServices, attach it to the entity, and return the
     * resulting Filesystem entities so callers can keep working with them.
     *
     * @param array<int, UploadedFile> $files
     * @return list<Filesystem>
     *
     * @throws Exception
     */
    private function handleFileUpload(
        Model $model,
        AppInterface $app,
        UserInterface $user,
        array $files,
        AllowedFileExtensionEnum $allowed = AllowedFileExtensionEnum::WORK_FILES,
    ): array {
        $filesystem = new FilesystemServices(
            $app,
            isset($model->company) && $model->company instanceof CompanyInterface ? $model->company : null
        );

        $entities = [];

        foreach ($files as $file) {
            if (! in_array($file->extension(), $allowed->getAllowedExtensions())) {
                throw new Exception('Invalid file format ' . $file->extension());
            }

            $filesystemEntity = $filesystem->upload($file, $user);
            new AttachFilesystemAction($filesystemEntity, $model)->execute($file->getClientOriginalName());

            $entities[] = $filesystemEntity;
        }

        return $entities;
    }

    public function uploadImageToEntity(
        Model $model,
        AppInterface $app,
        UserInterface $user,
        UploadedFile $file,
        string $fieldName
    ): Model {
        if (! in_array($file->extension(), AllowedFileExtensionEnum::ONLY_IMAGES->getAllowedExtensions())) {
            throw new Exception('Invalid file format ' . $file->extension());
        }

        $filesystem = new FilesystemServices(
            $app,
            isset($model->company) && $model->company instanceof CompanyInterface ? $model->company : null
        );
        $filesystemEntity = $filesystem->upload($file, $user);
        new AttachFilesystemAction($filesystemEntity, $model)->execute($fieldName);

        return $model;
    }
}
