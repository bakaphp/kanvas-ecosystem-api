<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Config;

use Kanvas\Config\Enums\ConfigEnums;

class ConfigManagement
{
    public function getConfig(mixed $root, array $request): array
    {
        $enum = $request['module'];
        $class = ConfigEnums::fromName($enum);
        $entity = $class::getByUuid($request['entity_uuid']);
        $config = [];
        foreach ($entity->getAll() as $key => $value) {
            $config[] = [
                'key' => $key,
                'value' => $value,
                'module' => $enum,
                'entity_uuid' => $request['entity_uuid'],
            ];
        }

        return $config;
    }
}
