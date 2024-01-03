<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Config;

use Kanvas\Config\Enums\ConfigEnums;

class ConfigManagement
{
    public function setConfig(mixed $root, array $request): bool
    {
        $enum = $request['input']['module'];
        $class = ConfigEnums::fromName($enum);
        $entity = $class::getByUuid($request['input']['entity_uuid']);
        $entity->set($request['input']['key'], $request['input']['value']);

        return true;
    }

    public function deleteConfig(mixed $root, array $request): bool
    {
        $enum = $request['input']['module'];
        $class = ConfigEnums::fromName($enum);
        $entity = $class::getByUuid($request['input']['entity_uuid']);
        $entity->del($request['input']['key']);

        return true;
    }
}
