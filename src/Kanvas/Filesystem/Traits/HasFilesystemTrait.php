<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Traits;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use RuntimeException;

trait HasFilesystemTrait
{
    /**
     * attach a file system or multiple to this entity.
     *
     * @param Filesystem|array $files
     *
     * @throws Exception
     *
     * @return bool
     */
    public function attach(Filesystem $files, string $fieldName) : bool
    {
        $attachFilesystem = new AttachFilesystemAction($files, $this);
        $attachFilesystem->execute($fieldName);

        return true;
    }

    /**
     * Attach multiple files.
     *
     * @param array $files<file: UploadedFile, fieldName: string>
     *
     * @throws RuntimeException
     *
     * @return bool
     */
    public function attachMultiple(array $files) : bool
    {
        foreach ($files as $file) {
            if (!isset($file['file']) || !isset($file['fieldName'])) {
                throw new ValidationException('Missing file || fieldName index');
            }

            $attachFilesystem = new AttachFilesystemAction($file['file'], $this);
            $attachFilesystem->execute($file['fieldName']);
        }

        return true;
    }

    /**
     * Get list of files attached to this model.
     *
     * @return Collection<FilesystemEntities>
     */
    public function getFiles() : Collection
    {
        return FilesystemEntitiesRepository::getFilesByEntity($this);
    }

    /**
     * Delete all files associated with this entity.
     *
     * @return int
     */
    public function deleteFiles() : int
    {
        return FilesystemEntitiesRepository::deleteAllFilesFromEntity($this);
    }
}
