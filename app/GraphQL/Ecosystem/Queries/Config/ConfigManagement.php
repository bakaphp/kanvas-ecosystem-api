<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Config;

class ConfigManagement
{
    public function getAppSetting(mixed $root, array $request): array
    {
        return $this->getConfig(ConfigEnums::fromName('APPS'), $request);
    }

    public function getCompanySetting(mixed $root, array $request): array
    {
        return $this->getConfig(ConfigEnums::fromName('COMPANIES'), $request);
    }

    public function getUserSetting(mixed $root, array $request): array
    {
        return $this->getConfig(ConfigEnums::fromName('USERS'), $request);
    }

    public function getConfig(string $module, array $request): array
    {
        $entity = $module::getByUuid($request['entity_uuid']);
        $config = [];
        foreach ($entity->getAll() as $key => $value) {
            $config[] = [
                'key' => $key,
                'value' => $value,
            ];
        }

        return $config;
    }
}
