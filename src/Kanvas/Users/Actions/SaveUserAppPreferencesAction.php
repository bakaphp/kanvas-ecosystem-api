<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UserConfig;

class SaveUserAppPreferencesAction
{
    public function __construct(
        private Users $user,
        private Apps $app,
        private array $preferences = [],
    ) {}

    public function execute(): void
    {
        // activeUserAppSettings is an array of keys that are allowed to be set, this makes sure that no upwanted settings are stored. This array of keys comes from the app setting in_app_user_settings_keys
        $activeUserAppSettings = $this->app->get('in_app_user_settings_keys');
        $savedAppPreferences = [];
        foreach ($activeUserAppSettings as $setting) {
            // check if the setting is in the preferences array, if not con
            if (! array_key_exists($setting, $this->preferences)) {
                continue;
            }
            $savedAppPreferences[$setting] = $this->preferences[$setting];
        }

        UserConfig::updateOrCreate(
            [
                'users_id' => $this->user->getId(),
                'name' => 'user_app_' . $this->app->getId() . '_preferences',
            ],
            [
                'value' => $savedAppPreferences,
                'is_public' => 1,
            ],
        );
    }
}
