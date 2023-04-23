<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\DataTransferObject\AppSettingsInput;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Users\Repositories\UsersRepository;

class AppSettingsMutation
{
    /**
     * Save app setting.
     */
    public function saveSettings(mixed $root, array $req): mixed
    {
        $app = AppsRepository::findFirstByKey($req['id']);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        $appSetting = AppSettingsInput::from($req['input']);

        $app->set($appSetting->name, $appSetting->value);

        return $app->get($appSetting->name);
    }
}
