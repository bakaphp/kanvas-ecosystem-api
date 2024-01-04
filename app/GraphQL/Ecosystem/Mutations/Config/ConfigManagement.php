<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Config;

use Kanvas\Config\Enums\ConfigEnums;

class ConfigManagement
{
    public function setAppSetting(mixed $root, array $request): bool
    {
        return $this->setConfig(ConfigEnums::fromName('APPS'), $request['input']);
    }

    public function deleteAppSetting(mixed $root, array $request): bool
    {
        return $this->deleteConfig(ConfigEnums::fromName('APPS'), $request['input']);
    }

    public function setCompanySetting(mixed $root, array $request): bool
    {
        return $this->setConfig(ConfigEnums::fromName('COMPANIES'), $request['input']);
    }

    public function deleteCompanySetting(mixed $root, array $request): bool
    {
        return $this->deleteConfig(ConfigEnums::fromName('COMPANIES'), $request['input']);
    }

    public function setUserSetting(mixed $root, array $request): bool
    {
        return $this->setConfig(ConfigEnums::fromName('USERS'), $request['input']);
    }

    public function deleteUserSetting(mixed $root, array $request): bool
    {
        return $this->deleteConfig(ConfigEnums::fromName('USERS'), $request['input']);
    }

    public function setConfig(string $module, array $config): bool
    {
        $entity = $module::getByUuid($request['input']['entity_uuid']);
        $entity->set($config['key'], $config['value']);

        return true;
    }

    public function deleteConfig(string $module, array $request): bool
    {
        $entity = $module::getByUuid($request['input']['entity_uuid']);
        $entity->delete($config['key']);

        return true;
    }
}
