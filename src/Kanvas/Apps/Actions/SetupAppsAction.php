<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\SystemModules\Actions\CreateSystemModulesAction;
use Kanvas\SystemModules\DataTransferObject\SystemModulesData;
use Kanvas\SystemModules\Enums\Defaults as SystemModulesDefaults;

/**
 * SetupAction Class.
 */
class SetupAppsAction
{
    /**
     * Construct function.
     *
     * @param AppsPostData $data
     */
    public function __construct(
        protected Apps $app
    ) {
    }

    /**
     * Invoke function.
     *
     * @return Apps
     */
    public function execute() : Apps
    {
        $this->setupSettings();
        $this->setupSystemModules();

        return $this->app;
    }

    /**
     * Create new App Settings.
     *
     * @return void
     */
    protected function setupSettings() : void
    {
        foreach (AppSettingsEnums::cases() as $case) {
            $this->app->set($case->getValue(), AppEnums::fromName($case->name));
        }
    }

    /**
     * Create new App Settings.
     *
     * @return void
     */
    protected function setupSystemModules() : void
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
