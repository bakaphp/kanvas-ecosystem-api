<?php

declare(strict_types=1);

namespace Kanvas\AppsGroup\Settings\Actions;

use Kanvas\AppsGroup\Settings\Models\Settings;
use Kanvas\AppsGroup\Settings\DataTransferObject\AppsSettingsPostData;

class CreateAppsSettingsAction
{
    /**
     * Construct function
     */
    public function __construct(
        protected AppsSettingsPostData $data
    ) {
    }

    /**
     * Invoke function
     *
     * @return Apps
     */
    public function execute(): Settings
    {
        $settings = new Settings();
        $settings->apps_id = $this->data->apps_id;
        $settings->name = $this->data->name;
        $settings->value = $this->data->value;
        $settings->save();

        return $settings;
    }
}
