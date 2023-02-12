<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Kanvas\AccessControlList\Actions\CreateAppRoleAction;
use Kanvas\Apps\Enums\DefaultRoles;
use Kanvas\Apps\Enums\DefaultTemplates;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\SystemModules\Actions\CreateSystemModulesAction;
use Kanvas\SystemModules\DataTransferObject\SystemModulesData;
use Kanvas\SystemModules\Enums\Defaults as SystemModulesDefaults;
use Kanvas\Templates\Actions\CreateTemplate;
use Kanvas\Templates\DataTransferObject\TemplateInput;

/**
 * SetupAction Class.
 */
class SetupAppsAction
{
    /**
     * Construct function.
     *
     * @param Apps $app
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
    public function execute(): Apps
    {
        $this->initSSettings();
        $this->initSystemModules();
        $this->initTemplates();
        $this->initRoles();

        return $this->app;
    }

    /**
     * Create new App Settings.
     *
     * @return void
     */
    protected function initSSettings(): void
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
    protected function initSystemModules(): void
    {
        foreach (SystemModulesDefaults::cases() as $case) {
            $data = $case->getValue();
            $data['apps_id'] = $this->app->id;
            $systemModuleData = SystemModulesData::fromArray($data);
            $systemModule = new CreateSystemModulesAction($systemModuleData);
            $systemModule->execute();
        }
    }

    /**
     * Create default roles.
     *
     * @return void
     */
    protected function initRoles(): void
    {
        foreach (DefaultRoles::cases() as $case) {
            $createRole = new CreateAppRoleAction(
                $this->app,
                $case->getValue()
            );

            $createRole->execute();
        }
    }

    /**
     * init templates.
     *
     * @return void
     */
    protected function initTemplates(): void
    {
        foreach (DefaultTemplates::cases() as $case) {
            $createTemplate = new CreateTemplate(
                new TemplateInput(
                    $this->app,
                    $case->name,
                    $case->value
                )
            );

            $createTemplate->execute();
        }
    }
}
