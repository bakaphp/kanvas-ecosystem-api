<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\AppsGroup\Settings\Actions\CreateAppsSettingsAction;
use Kanvas\SystemModules\Actions\CreateSystemModulesAction;
use Kanvas\AppsGroup\Settings\DataTransferObject\AppsSettingsPostData;
use Kanvas\SystemModules\DataTransferObject\SystemModulesData;
use Kanvas\AppsGroup\Settings\Enums\Defaults as AppsSettingsDefaults;
use Kanvas\SystemModules\Enums\Defaults as SystemModulesDefaults;

/**
 * SetupAction Class
 */
class SetupAppsAction
{
    /**
     * Construct function
     *
     * @param AppsPostData $data
     */
    public function __construct(
        protected Apps $app
    ) {
    }

    /**
     * Invoke function
     *
     * @return Apps
     */
    public function execute(): Apps
    {
        $this->setupSettings();
        $this->setupSystemModules();

        return $this->app;
    }

    /**
     * Create new App Settings
     *
     * @return void
     */
    protected function setupSettings(): void
    {
        foreach (AppsSettingsDefaults::cases() as $case) {
            $settingData = AppsSettingsPostData::fromArray(
                [
                "apps_id" => $this->app->id,
                'name' => $case->name,
                'value' => (string)$case->getValue()
                ]
            );
            $setting = new CreateAppsSettingsAction($settingData);
            $setting->execute();
        }
    }

    /**
     * Create new App Settings
     *
     * @return void
     */
    protected function setupSystemModules(): void
    {
        foreach (SystemModulesDefaults::cases() as $case) {
            $data = $case->getValue();
            $data['apps_id'] = $this->app->id;
            $systemModuleData = SystemModulesData::fromArray($data);
            $systemModule = new CreateSystemModulesAction($systemModuleData);
            $systemModule->execute();
        }
    }
}
