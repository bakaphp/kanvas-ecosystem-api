<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\Enums\DefaultRoles;
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
     * @return Apps
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
        });

        return $app;
    }

    protected function settings(Apps $app): void
    {
        if ($app->settings()->count()) {
            return ;
        }

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
                'name' => 'filesystem',
                'value' => 's3',
            ], [
                'name' => 'allow_user_registration',
                'value' => '1',
            ], [
                'name' => 'registered',
                'value' => 1,
            ], [
                'name' => 'titles',
                'value' => $app->name,
            ], [
                'name' => 'base_color',
                'value' => '#61c2cc',
            ], [
                'name' => 'secondary_color',
                'value' => '#9ee5b5',
            ], [
                'name' => 'allow_social_auth',
                'value' => '0',
            ], [
                'name' => 'show_notifications',
                'value' => '1',
            ], [
                'name' => 'delete_images_on_empty_files_field',
                'value' => '1',
            ], [
                'name' => 'public_images',
                'value' => '0',
            ], [
                'name' => 'default_feeds_comments',
                'value' => '3',
            ],
        ];

        foreach ($settings as $key => $value) {
            $app->set($value['name'], $value['value']);
        }
    }

    /**
     * Create the system modules.
     *
     * @param Apps $app
     *
     * @return void
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
            'Admins',
            DefaultRoles::USER->getValue(),
            DefaultRoles::MANAGER->getValue(),
            DefaultRoles::DEVELOPER->getValue(),
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
        }
    }
}
