<?php

namespace Kanvas\Traits;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Filesystem\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Filesystem\Repositories\FilesystemRepository;
use Kanvas\Filesystem\FilesystemEntities\Models\FilesystemEntities;

trait FilesystemAttachTrait
{
    // use CacheKeys;
    public $entity;

    public $systemModuleClass;

    public $uploadedFiles = [];

    protected function setAssociatedModule(Model $model) : void
    {
        $this->entity = $model;
        $this->systemModuleClass = $model::class;
    }

    /**
     * Associated the list of uploaded files to this entity.
     *
     * call on the after saves
     *
     * @return void
     */
    protected function associateFileSystem() : bool
    {
        if (!empty($this->uploadedFiles) && is_array($this->uploadedFiles)) {
            foreach ($this->uploadedFiles as $file) {
                if (!isset($file['filesystem_id'])) {
                    continue;
                }

                try {
                    $fileSystem = FilesystemRepository::getById($file['filesystem_id']);
                    $this->attach([[
                        'id' => $file['id'] ?? 0,
                        'file' => $fileSystem,
                        'field_name' => $file['field_name'] ?? '',
                        'is_deleted' => $file['is_deleted'] ?? 0
                    ]]);
                } catch (Exception $e) {
                    continue;
                }
            }
        }

        return true;
    }

    /**
     * Given the array of files we will attach this files to the files.
     * [
     *  'file' => $file,
     *  'file_name' => 'dfadfa'
     * ];.
     *
     * @param array $files
     *
     * @return void
     * @todo This still does not work, needs to be checked
     */
    public function attach(array $files) : bool
    {
        $systemModule = SystemModules::getByModelName($this->systemModuleClass);
        $upload = false;

        foreach ($files as $file) {
            //im looking for the file inside an array
            if (!isset($file['file'])) {
                continue;
            }

            if (!$file['file'] instanceof FileSystem) {
                throw new RuntimeException('Cant attach a none Filesystem to this entity');
            }

            $fileSystemEntities = null;
            //check if we are updating the attachment
            if (isset($file['id']) && $id = (int) $file['id']) {
                $fileSystemEntities = FileSystemEntities::getByIdWithSystemModule($id, $systemModule);
            }

            //new attachment
            try {
                if (!is_object($fileSystemEntities)) {
                    $fileSystemEntities = new FileSystemEntities();
                    $fileSystemEntities->system_modules_id = $systemModule->getId();
                    $fileSystemEntities->companies_id = $file['file']->companies_id;
                    $fileSystemEntities->entity_id = $this->getId();
                    $fileSystemEntities->created_at = $file['file']->created_at;
                }

                $fileSystemEntities->filesystem_id = $file['file']->getId();
                $fileSystemEntities->field_name = $file['field_name'] ?? null;
                // Allow the frontend to dictate if the file is deleted or not.
                $fileSystemEntities->is_deleted = isset($file['is_deleted']) ? (int) $file['is_deleted'] : 0;
                $fileSystemEntities->saveOrFail();

                $upload = true;

                if (!is_null($this->filesNewAttachedPath())) {
                    $file['file']->move($this->filesNewAttachedPath());
                }
            } catch (Exception $e) {
                Di::getDefault()->get('log')->warning($e->getMessage());
                //we wont stop operation but wont attach 2 images to the same entity
            }
        }

        if ($upload) {
            $this->clearFileSystemCache();
        }

        return true;
    }
}
