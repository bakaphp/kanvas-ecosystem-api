<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Kanvas\Templates\Models\Templates;

class TemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        $defaultTemplate = Templates::create([
            'id' => 1,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'parent_template_id' => 0,
            'name' => 'Default',
            'template' => File::get(resource_path('views/emails/defaultTemplate.blade.php')),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Templates::create([
            'id' => 2,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'parent_template_id' => 0,
            'name' => 'user-email-update',
            'template' => File::get(resource_path('views/emails/defaultTemplate.blade.php')),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Templates::create([
            'id' => 3,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'name' => 'users-invite',
            'parent_template_id' => $defaultTemplate->id,
            'template' => File::get(resource_path('views/emails/userInvite.blade.php')),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Templates::create([
            'id' => 4,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'name' => 'change-password',
            'parent_template_id' => $defaultTemplate->id,
            'template' => File::get(resource_path('views/emails/passwordUpdated.blade.php')),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Templates::create([
            'id' => 5,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'name' => 'reset-password',
            'parent_template_id' => $defaultTemplate->id,
            'template' => File::get(resource_path('views/emails/resetPassword.blade.php')),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Templates::create([
            'id' => 6,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'name' => 'welcome',
            'parent_template_id' => $defaultTemplate->id,
            'template' => File::get(resource_path('views/emails/welcome.blade.php')),
            'created_at' => date('Y-m-d H:i:s'),
        ]);


        Templates::create([
            'id' => 7,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'name' => 'new-push-default',
            'parent_template_id' => 0,
            'template' => File::get(resource_path('views/emails/pushNotification.blade.php')),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
