<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Traits;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Enum\AllowedFileExtensionEnum;
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
        $filesystem = new FilesystemServices($app);

        foreach ($files as $file) {
            // Validate file extension
            if (! in_array($file->extension(), AllowedFileExtensionEnum::WORK_FILES->getAllowedExtensions())) {
                throw new Exception('Invalid file format ' . $file->extension());
            }

            // Upload file
            $filesystemEntity = $filesystem->upload($file, $user);

            // Attach file to the entity
            $action = new AttachFilesystemAction($filesystemEntity, $model);
            $action->execute($file->getClientOriginalName());
        }

        return $model;
    }
}
