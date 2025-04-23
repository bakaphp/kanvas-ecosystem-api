<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Traits;

use Baka\Enums\StateEnums;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Enums\AllowedFileExtensionEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use RuntimeException;

trait HasFilesystemTrait
{
    /**
     * attach a file system or multiple to this entity.
     *
     * @throws Exception
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
     * @throws Exception
     */
    public function addFileFromUrl(string $url, string $fieldName, ?Apps $app = null): bool
    {
        $companyId = $this->companies_id ?? AppEnums::GLOBAL_COMPANY_ID->getValue();

        //@todo allow to share media between company only of it the apps specifies it
        $fileSystem = Filesystem::fromApp()
            ->when($companyId > 0, function ($query) use ($companyId) {
                $company = Companies::getById($companyId);

                return $query->fromCompany($company);
            })
            ->where('url', $url)
            ->firstOrNew();

        if (!$fileSystem->exists) {
            $fileInfo = pathinfo($url);

            $extension = $fileInfo['extension'] ?? 'unknown';
            $fileSystem->companies_id = $companyId;
            $fileSystem->apps_id = $app ? $app->getId() : app(Apps::class)->getId();
            $fileSystem->users_id = $this->users_id ?? (auth()->check() ? auth()->user()->getKey() : 0);
            $fileSystem->path = $fileInfo['dirname'].'/'.$fileInfo['basename'];
            $fileSystem->url = $url;
            $fileSystem->name = $fileInfo['basename'];
            $fileSystem->file_type = $this->cleanExtension($extension);
            $fileSystem->size = 0;
            $fileSystem->saveOrFail();
        }

        $attachFilesystem = new AttachFilesystemAction($fileSystem, $this);

        return $attachFilesystem->execute($fieldName) instanceof FilesystemEntities;
    }

    public function addMultipleFilesFromUrl(array $files): bool
    {
        $filesystem = new FilesystemServices($this->app ?? app(Apps::class));

        foreach ($files as $file) {
            if (!isset($file['url']) || !isset($file['name'])) {
                throw new ValidationException('Missing url || name index');
            }

            if (isset($file['file']) && $file['file'] instanceof UploadedFile) {
                // Validate file extension
                if (!in_array($file['file']->extension(), AllowedFileExtensionEnum::WORK_FILES->getAllowedExtensions())) {
                    throw new Exception('Invalid file format '.$file->extension());
                }

                // Attach file to the entity
                $action = new AttachFilesystemAction(
                    $filesystem->upload($file['file'], $this->user),
                    $this
                );
                $action->execute($file['file']->getClientOriginalName());
            } else {
                $this->addFileFromUrl($file['url'], $file['name']);
            }
        }

        return true;
    }

    public function overWriteFiles(array $files, ?Apps $app = null): bool
    {
        $existingFiles = $this->getFiles();
        $newFiles = collect($files);

        // Find files to delete
        $filesToDelete = $existingFiles->filter(function ($file) use ($newFiles) {
            return !$newFiles->contains('url', $file['url']);
        });

        // Soft delete the files (or handle deletion as per your logic)
        foreach ($filesToDelete as $fileDelete) {
            $fileDelete->delete();
        }

        // Add or update new files
        foreach ($newFiles as $file) {
            $this->addFileFromUrl($file['url'], $file['name'], $app);
        }

        return true;
    }

    /**
     * Attach multiple files.
     *
     * @param array $files<file: UploadedFile, fieldName: string>
     *
     * @throws RuntimeException
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

    public function getFileByName(string $name): ?FilesystemEntities
    {
        return FilesystemEntitiesRepository::getFileFromEntityByName($this, $name);
    }

    /**
     * Get list of files attached to this model.
     */
    public function files(): HasManyThrough
    {
        $app = $this->app ?? app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName(get_class($this), $app);

        return $this->hasManyThrough(
            Filesystem::class,
            FilesystemEntities::class,
            'entity_id',
            'id',
            'id',
            'filesystem_id'
        )->where(
            'filesystem_entities.system_modules_id',
            $systemModule->getId()
        )
        ->where(
            'filesystem_entities.is_deleted',
            StateEnums::NO->getValue()
        );
    }

    /**
     * Delete all files associated with this entity.
     */
    public function deleteFiles(): int
    {
        return FilesystemEntitiesRepository::deleteAllFilesFromEntity($this);
    }

    protected function cleanExtension(string $extension): string
    {
        $cleanExtension = explode('?', $extension)[0];
        $validExtension = preg_replace('/[^a-zA-Z0-9]/', '', $cleanExtension);

        return $validExtension;
    }

    public function getFilesQueryBuilder(): Builder
    {
        $app = $this->app ?? app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName(static::class, $app);

        $files = Filesystem::select(
            'filesystem_entities.uuid',
            'filesystem_entities.field_name',
            'filesystem.name',
            'filesystem.url',
            'filesystem.size',
            'filesystem.file_type',
            'filesystem.file_type as type',
            'filesystem_entities.id',
        )
            ->join('filesystem_entities', 'filesystem_entities.filesystem_id', '=', 'filesystem.id')
            ->where('filesystem_entities.entity_id', '=', $this->getKey())
            ->where('filesystem_entities.system_modules_id', '=', $systemModule->getKey())
            ->where('filesystem_entities.is_deleted', '=', StateEnums::NO->getValue())
            ->where('filesystem.is_deleted', '=', StateEnums::NO->getValue());

        $files->when(isset($this->companies_id) && !$app->get(AppSettingsEnums::GLOBAL_APP_IMAGES->getValue()), function ($query) {
            $query->where('filesystem_entities.companies_id', $this->companies_id);
        });

        return $files;
    }
}
