<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Kanvas\Templates\Models\Templates;
use Kanvas\Templates\Repositories\DefaultTemplateRepository;

class TemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        Templates::create([
            'id' => 1,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'parent_template_id' => 0,
            'name' => 'Default',
            'template' => DefaultTemplateRepository::getDefaultTemplate(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Templates::create([
            'id' => 2,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'parent_template_id' => 0,
            'name' => 'user-email-update',
            'template' => DefaultTemplateRepository::getDefaultTemplate(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Templates::create([
            'id' => 3,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'name' => 'users-invite',
            'parent_template_id' => 1,
            'template' => DefaultTemplateRepository::getUsersInvite(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Templates::create([
            'id' => 4,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'name' => 'change-password',
            'parent_template_id' => 1,
            'template' => DefaultTemplateRepository::getChangePassword(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Templates::create([
            'id' => 5,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'name' => 'reset-password',
            'parent_template_id' => 1,
            'template' => DefaultTemplateRepository::getResetPassword(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Templates::create([
            'id' => 6,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'name' => 'welcome',
            'parent_template_id' => 1,
            'template' => DefaultTemplateRepository::getWelcome(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);


        Templates::create([
            'id' => 7,
            'apps_id' => 0,
            'users_id' => 1,
            'companies_id' => 0,
            'name' => 'new-push-default',
            'parent_template_id' => 1,
            'template' => DefaultTemplateRepository::getNewPushDefault(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
