<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Traits;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Services\FilesystemServices;

trait HasMutationUploadFiles
{
    public function uploadFileToEntity(
        Model $model,
        AppInterface $app,
        UserInterface $user,
        array $request
    ): Model {
        $filesystem = new FilesystemServices($app);
        $file = $request['file'];

        in_array($file->extension(), ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'json', 'pdf', 'txt', 'text']) ?: throw new Exception('Invalid file format ' . $file->extension());

        $filesystemEntity = $filesystem->upload($file, $user);
        $action = new AttachFilesystemAction(
            $filesystemEntity,
            $model
        );
        $action->execute($file->getClientOriginalName());

        return $model;
    }
}
