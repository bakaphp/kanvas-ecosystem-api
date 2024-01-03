<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Config;

class ConfigManagement
{
    public function getConfig(mixed $root, array $request): array
    {
        $enum = $request['input']['module'];
        $class = ConfigEnums::fromName($enum);
        $entity = $class::getByUuid($request['input']['entity_uuid']);

        return $entity->getAll();
    }
}
