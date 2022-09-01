<?php

declare(strict_types=1);


namespace Kanvas\Filesystem\FilesystemEntities\DataTransferObject;

use Spatie\DataTransferObject\DataTransferObject;
use Illuminate\Http\Request;

/**
 * AppsData class
 */
class FilesystemEntitiesPostData extends DataTransferObject
{
    /**
     * Construct function
     *
     * @property int $filesystem_id
     * @property int $companies_id
     * @property int $system_modules_id
     * @property int $entity_id
     * @property string $field_name
     */
    public function __construct(
        public int $filesystem_id,
        public int $companies_id,
        public int $system_modules_id,
        public int $entity_id,
        public string $field_name,
    ) {
    }

    /**
     * Create new instance of DTO from request
     *
     * @param Request $request Request Input data
     *
     * @return self
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            filesystem_id: (int)$request->get('filesystem_id'),
            companies_id: (int)$request->get('companies_id'),
            system_modules_id: (int)$request->get('system_modules_id'),
            entity_id: (int)$request->get('entity_id'),
            field_name: $request->get('field_name'),
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
            filesystem_id: (int)$data['filesystem_id'],
            companies_id: (int)$data['companies_id'],
            system_modules_id: (int)$data['system_modules_id'],
            entity_id: (int)$data['entity_id'],
            field_name: $data['field_name'],
        );
    }
}
