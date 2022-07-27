<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Filesystem\DataTransferObject;

use Spatie\DataTransferObject\DataTransferObject;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Filesystem\Filesystem\Models\Filesystem;

/**
 * ResponseData class
 */
class SingleResponseData extends DataTransferObject
{
    /**
     * Construct function
     *
     * @property int $id
     * @property int $users_id
     * @property int $companies_id
     * @property int $apps_id
     * @property string $name
     * @property string $path
     * @property string $url
     * @property string $size
     * @property string $file_type
     * @property string $created_at
     * @property string $updated_at
     * @property int $is_deleted
     */
    public function __construct(
        public int $id,
        public int $users_id,
        public int $companies_id,
        public int $apps_id,
        public string $name,
        public string $path,
        public string $url,
        public string $size,
        public string $file_type,
        public string $created_at,
        public string $updated_at,
        public int $is_deleted
    ) {
    }

    /**
     * Create new instance of DTO from request
     *
     * @param Filesystem $filesystem
     *
     * @return self
     */
    public static function fromModel(Filesystem $filesystem): self
    {
        //Here we could filter the data we need

        return new self(
            id: $filesystem->id,
            users_id: $filesystem->users_id,
            companies_id: $filesystem->companies_id,
            apps_id: $filesystem->apps_id,
            name: $filesystem->name,
            path: $filesystem->path,
            url: $filesystem->url,
            size: $filesystem->size,
            file_type: $filesystem->file_type,
            created_at: $filesystem->created_at->format('Y-m-d H:i:s'),
            updated_at: $filesystem->updated_at->format('Y-m-d H:i:s'),
            is_deleted: $filesystem->is_deleted,
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
            users_id: $data['users_id'],
            companies_id: $data['companies_id'],
            apps_id: $data['apps_id'],
            name: $data['name'],
            path: $data['path'],
            url: $data['url'],
            size: $data['size'],
            file_type: $data['file_type'],
            created_at: $data['created_at'],
            updated_at: $data['updated_at'],
            is_deleted: (int)$data['is_deleted'],
        );
    }
}
