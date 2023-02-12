<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Traits;

use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
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
    public function addFile(Filesystem $files, string $fieldName): bool
    {
        $attachFilesystem = new AttachFilesystemAction($files, $this);
        $attachFilesystem->execute($fieldName);

        return true;
    }

    /**
     * attach file via url.
     *
     * @param string $url
     * @param string $fieldName
     *
     * @throws Exception
     *
     * @return bool
     */
    public function addFileFromUrl(string $url, string $fieldName): bool
    {
        $fileSystem = new Filesystem();
        $fileSystem->companies_id = $this->companies_id ?? AppEnums::GLOBAL_COMPANY_ID->getValue();
        $fileSystem->apps_id = app(Apps::class)->getId();
        $fileSystem->users_id = $this->users_id ?? (auth()->check() ? auth()->user()->getKey() : 0);
        $fileSystem->path = $url;
        $fileSystem->url = $url;
        $fileSystem->name = $url;
        $fileSystem->file_type = 'unknown';
        $fileSystem->size = 0;
        $fileSystem->saveOrFail();

        $attachFilesystem = new AttachFilesystemAction($fileSystem, $this);
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
    public function addMultipleFiles(array $files): bool
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
    public function getFiles(): Collection
    {
        //move to use $this->files();
        return FilesystemEntitiesRepository::getFilesByEntity($this);
    }

    /**
     * Get list of files attached to this model.
     *
     * @return HasManyThrough
     */
    public function files(): HasManyThrough
    {
        return $this->hasManyThrough(
            Filesystem::class,
            FilesystemEntities::class,
            'entity_id',
            'id',
            'id',
            'filesystem_id'
        )->where('filesystem_entities.system_modules_id', SystemModulesRepository::getByModelName(get_class($this))->getId())
        ->where('filesystem_entities.is_deleted', StateEnums::NO->getValue());
    }

    /**
     * Delete all files associated with this entity.
     *
     * @return int
     */
    public function deleteFiles(): int
    {
        return FilesystemEntitiesRepository::deleteAllFilesFromEntity($this);
    }
}
