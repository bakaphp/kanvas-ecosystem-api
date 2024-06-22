<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\DataTransferObject\AppKeyInput;
use Kanvas\Apps\Jobs\CreateSystemModuleJob;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Roles\Models\Roles;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
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
            $this->createEmailTemplate($app);
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
            ], [
                'name' => 'notification_from_user_id',
                'value' => $this->user->getId(),
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

    public function createEmailTemplate(Apps $app): void
    {
        // @todo
        $templates = [
            [
                'name' => 'Default',
                'template' => File::get(resource_path('views/email/defaultTemplate.blade.php')),
            ],
            [
                'name' => 'user-email-update',
                'template' => File::get(resource_path('views/email/defaultTemplate.blade.php')),
            ],
            [
                'name' => 'users-invite',
                'template' => File::get(resource_path('views/email/userInvite.blade.php')),
            ],
            [
                'name' => 'change-password',
                'template' => File::get(resource_path('views/email/passwordUpdated.blade.php')),
            ],
            [
                'name' => 'reset-password',
                'template' => File::get(resource_path('views/email/resetPassword.blade.php')),
            ],
            [
                'name' => 'welcome',
                'template' => File::get(resource_path('views/email/welcome.blade.php')),
            ],
            [
                'name' => 'new-push-default',
                'template' => File::get(resource_path('views/email/pushNotification.blade.php')),
            ],
        ];

        $dto = new TemplateInput(
            $app,
            $templates[0]['name'],
            $templates[0]['template'],
            null,
            $this->user
        );

        $action = new CreateTemplateAction($dto);
        $parent = $action->execute();

        //remove first
        array_shift($templates);

        foreach ($templates as $template) {
            $dto = new TemplateInput(
                $app,
                $template['name'],
                $template['template'],
                null,
                $this->user
            );

            $action = new CreateTemplateAction($dto);
            $parent = $action->execute($template['name'] !== 'user-email-update' ? $parent : null);
        }
    }
}
