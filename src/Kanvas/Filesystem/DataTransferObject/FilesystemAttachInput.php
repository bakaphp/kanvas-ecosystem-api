<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\DataTransferObject;

use Spatie\LaravelData\Data;

class FilesystemAttachInput extends Data
{
    public function __construct(
        public string $filesystemUuid,
        public string $fieldName,
        public string $systemModuleUuid,
        public string $entityId,
    ) {
    }

    public static function viaRequest(array $data): self
    {
        return new self(
            $data['filesystem_uuid'],
            $data['field_name'],
            $data['system_module_uuid'],
            $data['entity_id']
        );
    }
}
