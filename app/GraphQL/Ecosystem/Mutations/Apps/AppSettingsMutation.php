<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Actions\CreateAppSettings;
use Kanvas\Apps\DataTransferObject\AppSettingsInput;
use Kanvas\Apps\Repositories\AppsRepository;

class AppSettingsMutation
{
    /**
     * Save app setting.
     */
    public function saveSettings(mixed $root, array $req): mixed
    {
        $app = AppsRepository::findFirstByKey($req['id']);
        $appSetting = AppSettingsInput::from($req['input']);
        $action = new CreateAppSettings($app, $appSetting->name, $appSetting->value);
        $action->execute();

        return $app->get($appSetting->name);
    }

    /**
     * saveAppSmtpSettings
    */
    public function saveAppSmtpSettings(mixed $root, array $req): mixed
    {
        $app = AppsRepository::findFirstByKey($req['id']);
        foreach ($req['input'] as $key => $value) {
            $action = new CreateAppSettings($app, "smtp_{$key}", $value);
            $action->execute();
        }

        return true;
    }
}
