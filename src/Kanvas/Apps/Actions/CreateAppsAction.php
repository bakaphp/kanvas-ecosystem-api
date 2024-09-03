<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\DataTransferObject\AppKeyInput;
use Kanvas\Apps\Jobs\CreateSystemModuleJob;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Roles\Models\Roles;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;
use Kanvas\Users\Models\Users;
use Throwable;

class CreateAppsAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected AppInput $data,
        protected Users $user
    ) {
    }

    /**
     * Invoke function.
     *
     * @throws Throwable
     */
    public function execute(): Apps
    {
        $app = new Apps();
        DB::transaction(function () use ($app) {
            $app->fill([
                'name' => $this->data->name,
                'url' => $this->data->url,
                'description' => $this->data->description,
                'domain' => $this->data->domain,
                'is_actived' => $this->data->is_actived,
                'ecosystem_auth' => $this->data->ecosystem_auth,
                'payments_active' => $this->data->payments_active,
                'is_public' => $this->data->is_public,
                'domain_based' => $this->data->domain_based,
            ]);
            $app->saveOrFail();

            $app->associateUser($this->user, $this->data->is_actived);

            $this->settings($app);
            $this->systemModules($app);
            $this->acl($app);
            CreateSystemModuleJob::dispatch($app);
            (new CreateAppKeyAction(new AppKeyInput(
                'Default',
                $app,
                $this->user
            )))->execute();
            $this->user->assign(RolesEnums::OWNER->value);

            //@todo
            $syncEmailTemplate = new SyncEmailTemplateAction($app, $this->user);
            $syncEmailTemplate->execute();
        });
        Artisan::call('kanvas:update-abilities', [
            'app' => $app->key,
        ]);

        return $app;
    }

    protected function settings(Apps $app): void
    {
        $settings = [
            [
                'name' => 'language',
                'value' => 'EN',
            ], [
                'name' => 'timezone',
                'value' => 'America/New_York',
            ], [
                'name' => 'currency',
                'value' => 'USD',
            ], [
                'name' => 'allow_user_registration',
                'value' => '1',
            ], [
                'name' => 'show_notifications',
                'value' => '1',
            ], [
                'name' => 'global_app_images',
                'value' => '1',
            ],
        ];

        foreach ($settings as $key => $value) {
            $app->set($value['name'], $value['value']);
        }
    }

    /**
     * Create the system modules.
     */
    public function systemModules(Apps $app): void
    {
        $modules = [
            Companies::class,
            Users::class,
            Roles::class,
        ];

        foreach ($modules as $module) {
            $createSystemModules = new CreateInCurrentAppAction($app);
            $createSystemModules->execute($module);
        }
    }

    /**
     * Add default roles.
     */
    public function acl(Apps $app): void
    {
        $roles = [
            //'Admins',
            RolesEnums::OWNER->value,
            RolesEnums::ADMIN->value, //replace from admins when migration is complete
            RolesEnums::USER->value,
            RolesEnums::MANAGER->value,
            RolesEnums::DEVELOPER->value,
        ];

        foreach ($roles as $role) {
            $roles = Roles::firstOrCreate([
                'name' => $role,
                'apps_id' => $app->getId(),
            ], [
                'companies_id' => 1,
                'is_active' => 1,
                'scope' => 0,
            ]);

            $newRole = new CreateRoleAction(
                $role,
                $role,
                $app
            );
            $newRole->execute();
        }
    }
}
