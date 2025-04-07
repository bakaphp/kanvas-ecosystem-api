<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\DataTransferObject;

use Kanvas\SystemModules\Contracts\SystemModuleInputInterface;
use Spatie\LaravelData\Data;

/**
 * SystemModuleEntityInput class.
 */
class SystemModuleEntityInput extends Data implements SystemModuleInputInterface
{
    public function __construct(
        public string $name,
        public string $systemModuleUuid,
        public string $entityId,
        public mixed $data = null,
    ) {
    }

    public static function viaRequest(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            systemModuleUuid: (string) $data['system_module_uuid'],
            entityId: (string) $data['entity_id'],
            data: $data['data'] ?? $data['value'] ?? null,
        );
    }
}
