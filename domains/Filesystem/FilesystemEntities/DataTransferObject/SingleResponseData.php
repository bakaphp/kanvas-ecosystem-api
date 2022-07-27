<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\FilesystemEntities\DataTransferObject;

use Spatie\DataTransferObject\DataTransferObject;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Filesystem\FilesystemEntities\Models\FilesystemEntities;

/**
 * ResponseData class
 */
class SingleResponseData extends DataTransferObject
{
    /**
     * Construct function
     *
     * @param int $id
     * @property int $filesystem_id
     * @property int $companies_id
     * @property int $system_modules_id
     * @property int $entity_id
     * @property string $field_name
     * @param string $created_at
     * @param string $updated_at
     * @param int $is_deleted
     */
    public function __construct(
        public int $id,
        public int $filesystem_id,
        public int $companies_id,
        public int $system_modules_id,
        public int $entity_id,
        public string $field_name,
        public string $created_at,
        public string $updated_at,
        public int $is_deleted
    ) {
    }

    /**
     * Create new instance of DTO from request
     *
     * @param FilesystemEntities $filesystemEntity
     *
     * @return self
     */
    public static function fromModel(FilesystemEntities $filesystemEntity): self
    {
        //Here we could filter the data we need

        return new self(
            id: $filesystemEntity->id,
            filesystem_id: $filesystemEntity->filesystem_id,
            companies_id: $filesystemEntity->companies_id,
            system_modules_id: $filesystemEntity->system_modules_id,
            entity_id: $filesystemEntity->entity_id,
            field_name: $filesystemEntity->field_name,
            created_at: $filesystemEntity->created_at->format('Y-m-d H:i:s'),
            updated_at: $filesystemEntity->updated_at->format('Y-m-d H:i:s'),
            is_deleted: $filesystemEntity->is_deleted,
        );
    }

    /**
     * Create new instance of DTO from array of data
     *
     * @param array $data Input data
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            filesystem_id: $data['filesystem_id'],
            companies_id: $data['companies_id'],
            system_modules_id: $data['system_modules_id'],
            entity_id: $data['entity_id'],
            field_name: $data['field_name'],
            created_at: $data['created_at'],
            updated_at: $data['updated_at'],
            is_deleted: (int)$data['is_deleted'],
        );
    }
}
