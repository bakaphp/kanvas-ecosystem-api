<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;

class SaveUserAppPreferencesAction
{
    public function __construct(
        private Users $user,
        private Apps $app,
        private array $preferences = [],
    ) {
    }

    public function execute(): void
    {
        // activeUserAppSettings is an array of keys that are allowed to be set, this makes sure that no upwanted settings are stored. This array of keys comes from the app setting in_app_user_settings_keys
        $activeUserAppSettings = $this->app->get('in_app_user_settings_keys');

        $preferencesAssoc = collect($this->preferences)->mapWithKeys(function ($item) {
            return [$item['key'] => $item['value']];
        })->toArray();

        foreach ($activeUserAppSettings as $setting) {
            if (! array_key_exists($preferencesAssoc[$setting], $activeUserAppSettings)) {
                continue;
            }
            $this->user->set($key, $value);
        }

        foreach ($preferencesAssoc as $key => $value) {
            if (! array_key_exists($key, $activeUserAppSettings)) {
                continue;
            }
            $this->user->set($key, $value);
        }
    }
}
