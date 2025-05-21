<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;

class AttachFilesystemAction
{
    public function __construct(
        protected Filesystem $filesystem,
        protected EloquentModel $entity
    ) {
    }

    public function execute(string $fieldName, ?int $id = null): FilesystemEntities
    {
        return DB::connection('ecosystem')->transaction(function () use ($fieldName, $id) {
            $systemModule = SystemModulesRepository::getByModelName($this->entity::class, $this->filesystem->app);
            $update = (int) $id > 0;
            $allowDuplicateFiles = $this->filesystem->app->get(AppSettingsEnums::FILESYSTEM_ALLOW_DUPLICATE_FILES_BY_NAME->getValue());

            if ($update) {
                // If we're updating an existing record, get it by ID
                $fileEntity = FilesystemEntitiesRepository::getByIdAndEntity((int) $id, $this->entity);
            } else {
                // Based on the DB structure, we know the uniqueentityfilesytem constraint is on:
                // filesystem_id, entity_id, companies_id, system_modules_id

                // First, we'll look for an existing record with the same unique constraint
                // This will prevent any duplication for this filesystem and entity
                $existingEntity = FilesystemEntities::where([
                    'filesystem_id' => $this->filesystem->getKey(),
                    'entity_id' => $this->entity->getKey(),
                    'companies_id' => $this->filesystem->companies_id,
                    'system_modules_id' => $systemModule->getKey(),
                ])->first();

                if ($existingEntity) {
                    // If we found a record with the same unique constraint, use it
                    $fileEntity = $existingEntity;
                } else {
                    // If we're not allowing duplicate files by name, check if there's
                    // already a file for this entity with the same field_name
                    if (! $allowDuplicateFiles) {
                        $existingByFieldName = FilesystemEntities::where([
                            'entity_id' => $this->entity->getKey(),
                            'system_modules_id' => $systemModule->getKey(),
                            'field_name' => $fieldName,
                            'is_deleted' => StateEnums::NO->getValue(),
                        ])->first();

                        if ($existingByFieldName) {
                            // If we found a record with the same field name, use it
                            // and update its filesystem_id
                            $fileEntity = $existingByFieldName;
                            $fileEntity->filesystem_id = $this->filesystem->getKey();
                            $fileEntity->saveOrFail();
                        } else {
                            // No existing record found, create a new one
                            $fileEntity = $this->createFileEntity($fieldName, $systemModule);
                        }
                    } else {
                        // We allow duplicate files by name, so create a new one
                        $fileEntity = $this->createFileEntity($fieldName, $systemModule);
                    }
                }
            }

            // Check if we need to update the record
            if ($fileEntity->filesystem_id != $this->filesystem->getKey()) {
                $fileEntity->filesystem_id = $this->filesystem->getKey();
                $runUpdate = true;
            }

            if ($fileEntity->field_name != $fieldName) {
                $fileEntity->field_name = $fieldName;
                $runUpdate = true;
            }

            if ($fileEntity->is_deleted == StateEnums::YES->getValue()) {
                $fileEntity->is_deleted = StateEnums::NO->getValue();
                $runUpdate = true;
            }

            // Now update fields if necessary
            $needsUpdate = false;

            // Update field_name if needed
            if ($fileEntity->field_name != $fieldName) {
                $fileEntity->field_name = $fieldName;
                $needsUpdate = true;
            }

            // Ensure it's not marked as deleted
            if ($fileEntity->is_deleted == StateEnums::YES->getValue()) {
                $fileEntity->is_deleted = StateEnums::NO->getValue();
                $needsUpdate = true;
            }

            // Save changes if needed
            if ($needsUpdate) {
                $fileEntity->saveOrFail();
            }

            // Flush cache if method exists
            if (method_exists($fileEntity, 'flushCache')) {
                $fileEntity->flushCache();
            }

            // Fire events after successful database operations
            if ($this->entity->hasWorkflow()) {
                $this->entity->fireWorkflow(WorkflowEnum::ATTACH_FILE->value);
            }

            if (method_exists($this->entity, 'clearLightHouseCache')) {
                $this->entity->clearLightHouseCacheJob();
            }

            if (method_exists($this->entity, 'searchable')) {
                $this->entity->searchable();
            }

            return $fileEntity;
        });
    }

    /**
     * Helper method to create a new file entity with proper error handling
     */
    private function createFileEntity(string $fieldName, SystemModules $systemModule): FilesystemEntities
    {
        try {
            // Try to create the entity
            return FilesystemEntities::create([
                'entity_id' => $this->entity->getKey(),
                'system_modules_id' => $systemModule->getKey(),
                'companies_id' => $this->filesystem->companies_id,
                'filesystem_id' => $this->filesystem->getKey(),
                'field_name' => $fieldName,
                'is_deleted' => StateEnums::NO->getValue(),
            ]);
        } catch (QueryException $e) {
            // Check if it's a duplicate key error (integrity constraint violation)
            if ($e->getCode() == 23000) {
                // Someone else created this record between our check and our insert
                // Find the record that was created
                $existingEntity = FilesystemEntities::where([
                    'filesystem_id' => $this->filesystem->getKey(),
                    'entity_id' => $this->entity->getKey(),
                    'companies_id' => $this->filesystem->companies_id,
                    'system_modules_id' => $systemModule->getKey(),
                ])->first();

                if ($existingEntity) {
                    // Return the existing entity
                    return $existingEntity;
                }
            }

            // If it's not a duplicate key error or we couldn't find the record, rethrow
            throw $e;
        }
    }
}
